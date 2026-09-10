<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;

/**
 * %env(tunnel:SOME_URL)% -- the tunnel's public base URL when one serves this app, otherwise
 * the variable's own value.
 *
 * The tunnel wins over the variable on purpose. A URL minted on this machine for something to
 * call back into this machine (a webhook, a QR code for a phone) is only correct if it names
 * this machine's tunnel, and the hand-copied alternative is exactly what drifted: .env.local
 * pointed mediary at laptop-ssai.scanstationai.work, a hostname no connector here serves. In
 * prod the bundle is disabled, no tunnel is found, and the variable passes straight through.
 *
 * Long-running workers resolve env vars once per container, so a worker started before the
 * tunnel came up keeps the fallback until it restarts (messenger:consume --time-limit bounds it).
 */
final class TunnelEnvVarProcessor implements EnvVarProcessorInterface
{
    public function __construct(
        private readonly Tunnel $tunnel,
    ) {
    }

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        if (null !== $url = $this->tunnel->url()) {
            return $url;
        }

        try {
            return (string) $getEnv($name);
        } catch (EnvNotFoundException) {
            return '';
        }
    }

    public static function getProvidedTypes(): array
    {
        return ['tunnel' => 'string'];
    }
}
