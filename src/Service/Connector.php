<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

/**
 * A cloudflared process running on THIS machine, as seen through its local metrics server.
 */
final readonly class Connector
{
    /**
     * @param list<IngressRule> $ingress
     */
    public function __construct(
        public string $metricsUrl,
        public ?string $connectorId,
        public int $readyConnections,
        public array $ingress,
    ) {
    }

    /** @return list<IngressRule> */
    public function rulesForPort(int $port): array
    {
        return array_values(array_filter($this->ingress, static fn(IngressRule $r): bool => $r->port === $port));
    }
}
