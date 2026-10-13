<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

/**
 * One entry of the Symfony CLI proxy index (http://127.0.0.1:7080/index.json), which maps
 * each registered project directory to the port its local web server is pinned to.
 */
final class ProxyProject
{
    /**
     * @param list<string> $domains
     */
    public function __construct(
        public readonly string $dir,
        public readonly ?int $port,
        public readonly string $scheme,
        public readonly array $domains,
    ) {
    }

    /** Directory basename, which is also the app segment of the tunnel hostname: ~/sites/ssai → ssai. */
    public string $app {
        get => basename($this->dir);
    }

    /** Where cloudflared should send traffic for this project, e.g. https://127.0.0.1:8021. */
    public ?string $origin {
        get => null === $this->port ? null : sprintf('%s://127.0.0.1:%d', $this->scheme, $this->port);
    }
}
