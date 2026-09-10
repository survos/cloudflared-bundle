<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * What the cloudflared processes on this machine are actually doing.
 *
 * Deliberately reads the RUNNING connectors rather than ~/.cloudflared/*.yml: a config file
 * says what a tunnel would serve if someone ran it, and this Mac holds credentials for tunnels
 * it must never run (laptop-ssai belongs to the lemur). Only a live process's /config says
 * what is being served from here.
 *
 * cloudflared binds its metrics server to the first free port in 127.0.0.1:20241-20245, so
 * each running tunnel is found by asking each of those ports for /ready.
 */
final class CloudflaredAgent
{
    /** @var list<Connector>|null */
    private ?array $connectors = null;

    /**
     * @param list<string> $metricsUrls
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly array $metricsUrls,
        private readonly ?string $binary = null,
    ) {
    }

    /** @return list<Connector> */
    public function connectors(): array
    {
        if ($this->connectors !== null) {
            return $this->connectors;
        }

        // Fire every /ready at once; a closed port fails in microseconds, so the scan costs one
        // round trip, not five.
        $ready = [];
        foreach ($this->metricsUrls as $url) {
            $ready[$url] = $this->httpClient->request('GET', rtrim($url, '/') . '/ready', ['timeout' => 1, 'max_duration' => 2]);
        }

        $connectors = [];
        foreach ($ready as $url => $response) {
            try {
                // /ready answers 503 while the tunnel is still connecting; that is still a connector.
                $status = $response->toArray(false);
                $config = $this->httpClient->request('GET', rtrim($url, '/') . '/config', ['timeout' => 1, 'max_duration' => 2])->toArray(false);
            } catch (ExceptionInterface) {
                continue;
            }

            $connectors[] = new Connector(
                metricsUrl: $url,
                connectorId: isset($status['connectorId']) ? (string) $status['connectorId'] : null,
                readyConnections: (int) ($status['readyConnections'] ?? 0),
                ingress: array_map(IngressRule::fromArray(...), array_values((array) ($config['config']['ingress'] ?? []))),
            );
        }

        return $this->connectors = $connectors;
    }

    /** @return list<IngressRule> every live rule on this machine that forwards to $port */
    public function rulesForPort(int $port): array
    {
        $rules = [];
        foreach ($this->connectors() as $connector) {
            array_push($rules, ...$connector->rulesForPort($port));
        }

        return $rules;
    }

    /**
     * Config files of the cloudflared processes running here, from their --config argument.
     *
     * @return list<string>
     */
    public function runningConfigFiles(): array
    {
        $process = new Process(['ps', '-axo', 'args=']);
        $process->run();

        $files = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (!str_contains($line, 'cloudflared') || !str_contains($line, ' tunnel')) {
                continue;
            }
            if (preg_match('/--config[= ](\S+)/', $line, $m) === 1) {
                $files[] = $m[1];
            } else {
                // No --config: cloudflared falls back to ~/.cloudflared/config.yml.
                $files[] = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.cloudflared/config.yml';
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * The tunnel: and credentials-file: keys of a cloudflared config file.
     *
     * @return array{tunnel: ?string, credentials: ?string}
     */
    public function tunnelOf(string $configFile): array
    {
        if (!is_readable($configFile)) {
            return ['tunnel' => null, 'credentials' => null];
        }
        $yaml = (array) Yaml::parseFile($configFile);

        return [
            'tunnel' => isset($yaml['tunnel']) ? (string) $yaml['tunnel'] : null,
            'credentials' => isset($yaml['credentials-file']) ? (string) $yaml['credentials-file'] : null,
        ];
    }

    /**
     * Every connector Cloudflare has for a tunnel, across ALL machines -- the only view that
     * can show a second machine serving the same tunnel. Needs the account cert
     * (~/.cloudflared/cert.pem) and network; returns null when either is missing.
     *
     * @return list<array{id: string, arch: string, version: string, originIps: list<string>, runAt: string}>|null
     */
    public function remoteConnectors(string $tunnel): ?array
    {
        $binary = $this->binary ?? (new ExecutableFinder())->find('cloudflared', null, ['/opt/homebrew/bin', '/usr/local/bin']);
        if ($binary === null) {
            return null;
        }

        $process = new Process([$binary, 'tunnel', 'info', '--output', 'json', $tunnel]);
        $process->setTimeout(20);
        $process->run();
        if (!$process->isSuccessful()) {
            return null;
        }

        $info = json_decode($process->getOutput(), true);
        if (!\is_array($info)) {
            return null;
        }

        $connectors = [];
        foreach ((array) ($info['conns'] ?? []) as $conn) {
            $connectors[] = [
                'id' => (string) ($conn['id'] ?? ''),
                'arch' => (string) ($conn['arch'] ?? ''),
                'version' => (string) ($conn['version'] ?? ''),
                'originIps' => array_values(array_unique(array_map(
                    static fn(array $edge): string => (string) ($edge['origin_ip'] ?? ''),
                    (array) ($conn['conns'] ?? []),
                ))),
                'runAt' => (string) ($conn['run_at'] ?? ''),
            ];
        }

        return $connectors;
    }
}
