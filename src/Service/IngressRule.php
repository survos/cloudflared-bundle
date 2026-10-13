<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

/**
 * One ingress rule, as the running cloudflared reports it (GET /config on its metrics port)
 * or as a config file declares it.
 */
final class IngressRule
{
    /**
     * @param array<string, mixed> $originRequest carried verbatim: settings like connectTimeout
     *                                            exist for hard-won reasons (truncated 17MB scan
     *                                            transfers), so nothing here may drop them
     */
    public function __construct(
        public readonly ?string $hostname,
        public readonly string $service,
        public readonly array $originRequest = [],
    ) {
    }

    /** @param array<string, mixed> $rule */
    public static function fromArray(array $rule): self
    {
        return new self(
            hostname: isset($rule['hostname']) && '' !== $rule['hostname'] ? (string) $rule['hostname'] : null,
            service: (string) ($rule['service'] ?? ''),
            originRequest: \is_array($rule['originRequest'] ?? null) ? $rule['originRequest'] : [],
        );
    }

    /** The same origin settings under another hostname -- how an m4-* twin inherits a mac-* rule's. */
    public function withHostname(string $hostname): self
    {
        return new self($hostname, $this->service, $this->originRequest);
    }

    public bool $noTlsVerify {
        get => (bool) ($this->originRequest['noTLSVerify'] ?? false);
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
        get => null === $this->hostname;
    }

    /** @return array<string, mixed> the shape cloudflared's YAML config expects */
    public function toArray(): array
    {
        $rule = null === $this->hostname ? [] : ['hostname' => $this->hostname];
        $rule['service'] = $this->service;
        if ([] !== $this->originRequest) {
            $rule['originRequest'] = $this->originRequest;
        }

        return $rule;
    }
}
