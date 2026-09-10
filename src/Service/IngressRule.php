<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

/**
 * One ingress rule, as the running cloudflared reports it (GET /config on its metrics port)
 * or as a config file declares it.
 */
final class IngressRule
{
    public function __construct(
        public readonly ?string $hostname,
        public readonly string $service,
        public readonly bool $noTlsVerify = false,
    ) {
    }

    /** @param array<string, mixed> $rule */
    public static function fromArray(array $rule): self
    {
        return new self(
            hostname: isset($rule['hostname']) && $rule['hostname'] !== '' ? (string) $rule['hostname'] : null,
            service: (string) ($rule['service'] ?? ''),
            noTlsVerify: (bool) ($rule['originRequest']['noTLSVerify'] ?? false),
        );
    }

    /** Local port this rule forwards to, or null for http_status:404 and non-loopback services. */
    public ?int $port {
        get {
            $parts = parse_url($this->service);
            if (!\is_array($parts) || !\in_array($parts['host'] ?? '', ['127.0.0.1', 'localhost', '::1', '[::1]'], true)) {
                return null;
            }

            return $parts['port'] ?? match ($parts['scheme'] ?? '') {
                'https' => 443,
                'http' => 80,
                default => null,
            };
        }
    }

    public bool $isCatchAll {
        get => $this->hostname === null;
    }

    /** @return array<string, mixed> the shape cloudflared's YAML config expects */
    public function toArray(): array
    {
        $rule = $this->hostname === null ? [] : ['hostname' => $this->hostname];
        $rule['service'] = $this->service;
        if ($this->noTlsVerify) {
            $rule['originRequest'] = ['noTLSVerify' => true];
        }

        return $rule;
    }
}
