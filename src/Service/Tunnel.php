<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The public URL that reaches THIS app from off the machine, derived from the tunnel that is
 * actually running rather than from a hostname someone typed into .env.local.
 *
 * Resolution: an explicit `hostname` in survos_cloudflared.yaml wins; otherwise the live
 * ingress of the local cloudflared is searched for a rule forwarding to this app's port
 * (preferring <machine>-<app>.<zone> when several do). No match means no tunnel serves this
 * app right now, and url() says so with null instead of returning a hostname that 530s.
 */
final class Tunnel
{
    private const CACHE_TTL = 30;

    public function __construct(
        private readonly SymfonyProxyIndex $proxyIndex,
        private readonly CloudflaredAgent $agent,
        private readonly CacheInterface $cache,
        private readonly string $projectDir,
        private readonly bool $enabled,
        private readonly string $zone,
        private readonly ?int $port = null,
        private readonly ?string $hostname = null,
        private readonly ?string $machine = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function app(): string
    {
        return basename($this->projectDir);
    }

    public function projectDir(): string
    {
        return $this->projectDir;
    }

    public function zone(): string
    {
        return $this->zone;
    }

    public function machine(): ?string
    {
        $machine = trim((string) $this->machine);

        return $machine === '' ? null : strtolower($machine);
    }

    public function configuredHostname(): ?string
    {
        $host = trim((string) $this->hostname);

        return $host === '' ? null : (parse_url($host, PHP_URL_HOST) ?: $host);
    }

    public function project(): ?ProxyProject
    {
        return $this->proxyIndex->forDir($this->projectDir);
    }

    /** This app's local port: the one pinned in survos_cloudflared.yaml, else the proxy index's. */
    public function port(): ?int
    {
        return $this->port ?? $this->project()?->port;
    }

    public function portSource(): string
    {
        return match (true) {
            $this->port !== null => 'survos_cloudflared.port',
            $this->project()?->port !== null => 'symfony proxy index',
            default => 'unknown',
        };
    }

    /** The hostname convention says this app should have: m4-ssai.scanstationai.work. */
    public function conventionalHost(?string $machine = null): ?string
    {
        $machine ??= $this->machine();

        return $machine === null ? null : sprintf('%s-%s.%s', $machine, $this->app(), $this->zone);
    }

    /** @return list<IngressRule> live rules on this machine that forward to this app's port */
    public function routes(): array
    {
        $port = $this->port();

        return $port === null ? [] : $this->agent->rulesForPort($port);
    }

    /** Uncached resolution; see the class docblock for the order. */
    public function host(): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        if (null !== $configured = $this->configuredHostname()) {
            return $configured;
        }

        $hosts = array_values(array_filter(array_map(static fn(IngressRule $r): ?string => $r->hostname, $this->routes())));
        if ($hosts === []) {
            return null;
        }
        $conventional = $this->conventionalHost();

        return \in_array($conventional, $hosts, true) ? $conventional : $hosts[0];
    }

    /**
     * https://<host>, or null when no tunnel serves this app. Cached briefly: callers build URLs
     * inside requests, and two loopback HTTP calls per URL would be noticeable on a busy page.
     */
    public function url(): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $url = $this->cache->get('survos_cloudflared.url.' . md5($this->projectDir), function (ItemInterface $item): string {
            $item->expiresAfter(self::CACHE_TTL);
            $host = $this->host();

            // Cache the miss too, as '', so a machine with no tunnel does not re-scan per request.
            return $host === null ? '' : 'https://' . $host;
        });

        return $url === '' ? null : $url;
    }
}
