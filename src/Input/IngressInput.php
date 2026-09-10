<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Input;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;

final class IngressInput
{
    /** @var list<string> */
    #[Argument('Apps to add, by directory basename in the proxy index (default: every app that has config/packages/survos_cloudflared.yaml)')]
    public array $apps = [];

    #[Option('Add every app in the proxy index that has a port, not just the opted-in ones')]
    public bool $all = false;

    #[Option('Machine prefix for new hostnames, e.g. m4 (default: survos_cloudflared.machine, i.e. CLOUDFLARED_MACHINE)')]
    public ?string $machine = null;

    #[Option('cloudflared config file to start from (default: the one the running tunnel was started with)')]
    public ?string $from = null;

    #[Option('Write the result to this file instead of printing it (never the --from file itself)')]
    public ?string $write = null;
}
