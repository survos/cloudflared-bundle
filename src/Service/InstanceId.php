<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

/**
 * A random id for this checkout of the app, stored under var/.
 *
 * The probe fetches /_cloudflared/whoami through the public hostname and compares the answer
 * with this value. A match proves the request came back to this very checkout; a different id
 * means another machine is serving the same hostname -- the load-balanced split that made
 * mediary's callbacks land on the lemur.
 */
final class InstanceId
{
    public function __construct(
        private readonly string $file,
    ) {
    }

    public function get(): string
    {
        if (is_readable($this->file) && '' !== $id = trim((string) file_get_contents($this->file))) {
            return $id;
        }

        $id = bin2hex(random_bytes(12));
        if (!is_dir(\dirname($this->file))) {
            @mkdir(\dirname($this->file), 0o775, true);
        }
        @file_put_contents($this->file, $id."\n", LOCK_EX);

        return $id;
    }
}
