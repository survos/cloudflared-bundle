<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Command;

use Survos\CloudflaredBundle\EventListener\TunnelRequestListener;
use Survos\CloudflaredBundle\Input\IngressInput;
use Survos\CloudflaredBundle\Service\CloudflaredAgent;
use Survos\CloudflaredBundle\Service\IngressRule;
use Survos\CloudflaredBundle\Service\InstanceId;
use Survos\CloudflaredBundle\Service\ProxyProject;
use Survos\CloudflaredBundle\Service\SymfonyProxyIndex;
use Survos\CloudflaredBundle\Service\Tunnel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The tunnel as seen from three sides: what this app knows locally (status), what the
 * internet actually reaches (probe), and what the machine's tunnel should serve (ingress).
 */
final class CloudflaredService
{
    public function __construct(
        private readonly Tunnel $tunnel,
        private readonly SymfonyProxyIndex $proxyIndex,
        private readonly CloudflaredAgent $agent,
        private readonly InstanceId $instanceId,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[AsCommand('cloudflared:status', 'Show this app\'s port, the cloudflared connectors running here, and the public URL they give it')]
    public function status(SymfonyStyle $io): int
    {
        $project = $this->tunnel->project();
        $port = $this->tunnel->port();
        $connectors = $this->agent->connectors();

        $rows = [
            ['App', sprintf('%s  (%s)', $this->tunnel->app(), $this->tunnel->projectDir())],
            ['Enabled', $this->tunnel->isEnabled() ? 'yes' : 'no -- survos_cloudflared.enabled is off in this environment'],
            ['Port', $port === null ? '<error>unknown</error> -- not in the proxy index; set survos_cloudflared.port' : sprintf('%d  (%s)', $port, $this->tunnel->portSource())],
        ];
        if ($project !== null) {
            $rows[] = ['Proxy', sprintf('%s, %s', $project->scheme, implode(', ', $project->domains) ?: 'no domains')];
        }
        $rows[] = ['Connectors', $connectors === []
            ? '<error>none</error> -- no cloudflared metrics server answers on 127.0.0.1:20241-20245'
            : implode("\n", array_map(static fn($c): string => sprintf('%s on %s (%d edge connections)', $c->connectorId ?? '?', $c->metricsUrl, $c->readyConnections), $connectors))];

        $routes = $this->tunnel->routes();
        $rows[] = ['Routes', $routes === []
            ? sprintf('<comment>none</comment> -- no live ingress rule forwards to port %s', $port ?? '?')
            : implode("\n", array_map(static fn(IngressRule $r): string => sprintf('%s → %s', $r->hostname, $r->service), $routes))];

        $conventional = $this->tunnel->conventionalHost();
        if ($conventional === null) {
            $rows[] = ['Convention', '<comment>machine unknown</comment> -- set CLOUDFLARED_MACHINE (e.g. m4) or survos_cloudflared.machine'];
        } else {
            $live = \in_array($conventional, array_map(static fn(IngressRule $r): ?string => $r->hostname, $routes), true);
            $rows[] = ['Convention', $conventional . ($live ? '' : '  <comment>(not in the live ingress -- see cloudflared:ingress)</comment>')];
        }
        if (null !== $configured = $this->tunnel->configuredHostname()) {
            $rows[] = ['Pinned host', $configured . '  (survos_cloudflared.hostname)'];
        }

        $url = $this->tunnel->host();
        $rows[] = ['Public URL', $url === null ? '<error>none</error>' : '<info>https://' . $url . '</info>'];

        $io->definitionList(...array_map(static fn(array $row): array => [$row[0] => $row[1]], $rows));

        if ($url === null) {
            return Command::FAILURE;
        }
        $io->text('Run <info>cloudflared:probe</info> to check that the hostname actually comes back to this machine.');

        return Command::SUCCESS;
    }

    #[AsCommand('cloudflared:probe', 'Fetch /_cloudflared/whoami through the public hostname and prove it answers from this checkout, every time')]
    public function probe(
        SymfonyStyle $io,
        #[Option('Requests to send; a split tunnel load-balances, so one lucky answer proves little')] int $count = 6,
        #[Option('Probe this hostname instead of the resolved one')] ?string $host = null,
    ): int {
        $host ??= $this->tunnel->host();
        if ($host === null) {
            $io->error('No public hostname for this app -- run cloudflared:status to see why.');

            return Command::FAILURE;
        }

        $expected = $this->instanceId->get();
        $url = sprintf('https://%s%s', $host, TunnelRequestListener::WHOAMI_PATH);
        $io->section(sprintf('Probing %s (%d requests), expecting instance %s', $url, $count, $expected));

        $tally = [];
        for ($i = 0; $i < max(1, $count); ++$i) {
            $verdict = $this->whoami($url, $expected);
            $tally[$verdict] = ($tally[$verdict] ?? 0) + 1;
        }
        foreach ($tally as $verdict => $n) {
            $io->writeln(sprintf('  %2d × %s', $n, $verdict));
        }
        $allHere = array_keys($tally) === ['here'];

        $foreign = $this->reportConnectors($io);

        if ($allHere && $foreign === 0) {
            $io->success(sprintf('https://%s answers from this checkout on every request.', $host));

            return Command::SUCCESS;
        }

        $io->error($allHere
            ? 'Every probe came back here, but another machine is connected to the same tunnel -- Cloudflare will split traffic as soon as it answers.'
            : sprintf('https://%s does not reliably reach this checkout.', $host));

        return Command::FAILURE;
    }

    #[AsCommand('cloudflared:ingress', 'Generate this machine\'s cloudflared ingress from the Symfony proxy index, keeping every existing rule')]
    public function ingress(SymfonyStyle $io, #[MapInput] IngressInput $input): int
    {
        $machine = $input->machine !== null ? strtolower(trim($input->machine)) : $this->tunnel->machine();
        if ($machine === null || $machine === '') {
            $io->error('No machine prefix. Pass --machine=m4, or export CLOUDFLARED_MACHINE=m4 once in your shell profile.');

            return Command::INVALID;
        }

        $from = $input->from ?? ($this->agent->runningConfigFiles()[0] ?? null);
        if ($from === null || !is_readable($from)) {
            $io->error('No cloudflared config to start from: no tunnel is running and --from was not given.');

            return Command::FAILURE;
        }
        $base = (array) Yaml::parseFile($from);
        $rules = array_map(IngressRule::fromArray(...), array_values((array) ($base['ingress'] ?? [])));
        $zone = $this->tunnel->zone();

        $projects = $this->selectProjects($io, $input);
        if ($projects === null) {
            return Command::INVALID;
        }

        $existingHosts = array_filter(array_map(static fn(IngressRule $r): ?string => $r->hostname, $rules));
        $added = [];
        foreach ($projects as $project) {
            $hostname = sprintf('%s-%s.%s', $machine, $project->app, $zone);
            if (\in_array($hostname, $existingHosts, true)) {
                continue;
            }
            // A rule already serving this port knows its origin better than the defaults do
            // (depot's 90s timeouts), so a new hostname for the same port copies it.
            $twin = array_find($rules, static fn(IngressRule $r): bool => !$r->isCatchAll && $r->port === $project->port);
            $added[] = $twin?->withHostname($hostname)
                ?? new IngressRule($hostname, (string) $project->origin, $project->scheme === 'https' ? ['noTLSVerify' => true] : []);
        }

        // New rules go after the existing named ones and before the catch-all, which must stay last.
        $named = array_values(array_filter($rules, static fn(IngressRule $r): bool => !$r->isCatchAll));
        $catchAll = array_values(array_filter($rules, static fn(IngressRule $r): bool => $r->isCatchAll)) ?: [new IngressRule(null, 'http_status:404')];
        $ingress = [...$named, ...$added, $catchAll[0]];

        $this->warnAboutDrift($io, $named, $zone);

        $config = array_filter([
            'tunnel' => $base['tunnel'] ?? null,
            'credentials-file' => $base['credentials-file'] ?? null,
        ]) + ['ingress' => array_map(static fn(IngressRule $r): array => $r->toArray(), $ingress)];

        $yaml = sprintf(
            "# Generated by cloudflared:ingress from %s and the Symfony proxy index.\n# Existing rules are carried over unchanged; comments are not -- keep the original for those.\n%s",
            $from,
            Yaml::dump($config, 4, 2, Yaml::DUMP_COMPACT_NESTED_MAPPING),
        );

        if ($input->write === null) {
            $io->writeln($yaml);
        } else {
            if (realpath($input->write) !== false && realpath($input->write) === realpath($from)) {
                $io->error('Refusing to overwrite the config the tunnel is running from (its comments would be lost). Write elsewhere, diff, then move it into place.');

                return Command::FAILURE;
            }
            file_put_contents($input->write, $yaml);
            $io->success(sprintf('Wrote %s', $input->write));
            $this->validate($io, $input->write);
        }

        if ($added === []) {
            $io->note('Nothing to add: every selected app already has its hostname.');

            return Command::SUCCESS;
        }

        $tunnelRef = (string) ($base['tunnel'] ?? '<tunnel>');
        $io->section('New hostnames need a DNS route to the tunnel (run once each):');
        foreach ($added as $rule) {
            $io->writeln(sprintf('  cloudflared tunnel route dns %s %s', $tunnelRef, $rule->hostname));
        }

        return Command::SUCCESS;
    }

    /** @return list<ProxyProject>|null null when an explicitly named app cannot be routed */
    private function selectProjects(SymfonyStyle $io, IngressInput $input): ?array
    {
        if ($input->apps !== []) {
            $projects = [];
            foreach ($input->apps as $app) {
                $project = $this->proxyIndex->forApp($app);
                if ($project?->port === null) {
                    $io->error(sprintf('"%s" has no port in the proxy index. Pin one in its .symfony.local.yaml (http.port) and start it once.', $app));

                    return null;
                }
                $projects[] = $project;
            }

            return $projects;
        }

        $withPort = array_filter($this->proxyIndex->projects(), static fn(ProxyProject $p): bool => $p->port !== null);
        if ($input->all) {
            return array_values($withPort);
        }

        // Opting in = having this bundle installed. A tunnel publishes a dev app to the whole
        // internet, so "every project on the machine" is not a safe default.
        return array_values(array_filter($withPort, static fn(ProxyProject $p): bool => is_file($p->dir . '/config/packages/survos_cloudflared.yaml')));
    }

    /**
     * A rule named for one app but forwarding to a port the proxy has given another -- how
     * laptop-ddys ended up pointing at rut. Reported, never rewritten: the rule may be deliberate.
     *
     * @param list<IngressRule> $rules
     */
    private function warnAboutDrift(SymfonyStyle $io, array $rules, string $zone): void
    {
        foreach ($rules as $rule) {
            if ($rule->port === null || $rule->hostname === null || !str_ends_with($rule->hostname, '.' . $zone)) {
                continue;
            }
            $owner = $this->proxyIndex->forPort($rule->port);
            if ($owner === null) {
                continue;
            }
            // <machine>-<app>[-suffix].<zone>: drop the zone, then the machine prefix.
            $label = substr($rule->hostname, 0, -\strlen('.' . $zone));
            $app = str_contains($label, '-') ? substr($label, strpos($label, '-') + 1) : $label;
            if ($app !== $owner->app && !str_starts_with($app, $owner->app . '-')) {
                $io->warning(sprintf('%s forwards to port %d, which the proxy index gives to %s.', $rule->hostname, $rule->port, $owner->app));
            }
        }
    }

    private function validate(SymfonyStyle $io, string $file): void
    {
        $binary = (new ExecutableFinder())->find('cloudflared', null, ['/opt/homebrew/bin', '/usr/local/bin']);
        if ($binary === null) {
            return;
        }
        $process = new Process([$binary, 'tunnel', '--config', $file, 'ingress', 'validate']);
        $process->run();
        $io->writeln($process->isSuccessful()
            ? '<info>cloudflared ingress validate: OK</info>'
            : '<error>cloudflared ingress validate failed:</error> ' . trim($process->getErrorOutput() . $process->getOutput()));
    }

    private function whoami(string $url, string $expected): string
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'max_redirects' => 0,
                'headers' => ['Cache-Control' => 'no-cache'],
                'query' => ['n' => bin2hex(random_bytes(4))],
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                return match ($status) {
                    530 => '530: no connector serves this hostname (Cloudflare 1033)',
                    502 => '502: a connector answered but its local origin is down',
                    404 => '404: answered by something without survos/cloudflared-bundle',
                    default => sprintf('HTTP %d', $status),
                };
            }
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            return 'error: ' . $e->getMessage();
        }

        $instance = (string) ($body['instance'] ?? '');

        return $instance === $expected
            ? 'here'
            : sprintf('ELSEWHERE: %s instance %s', $body['app'] ?? '?', $instance ?: '?');
    }

    /** @return int connectors on the running tunnels that are not on this machine */
    private function reportConnectors(SymfonyStyle $io): int
    {
        $localIds = array_filter(array_map(static fn($c): ?string => $c->connectorId, $this->agent->connectors()));
        $foreign = 0;

        foreach ($this->agent->runningConfigFiles() as $file) {
            $tunnelId = $this->agent->tunnelOf($file)['tunnel'];
            if ($tunnelId === null) {
                continue;
            }
            $remote = $this->agent->remoteConnectors($tunnelId);
            if ($remote === null) {
                $io->writeln(sprintf('  tunnel %s: <comment>connector list unavailable</comment> (needs ~/.cloudflared/cert.pem and network)', $tunnelId));
                continue;
            }
            foreach ($remote as $conn) {
                $mine = \in_array($conn['id'], $localIds, true);
                $foreign += $mine ? 0 : 1;
                $io->writeln(sprintf(
                    '  tunnel %s connector %s  %s %s from %s  %s',
                    substr($tunnelId, 0, 8),
                    substr($conn['id'], 0, 8),
                    $conn['arch'],
                    $conn['version'],
                    implode(',', $conn['originIps']),
                    $mine ? '<info>this machine</info>' : '<error>ANOTHER MACHINE</error>',
                ));
            }
        }

        return $foreign;
    }
}
