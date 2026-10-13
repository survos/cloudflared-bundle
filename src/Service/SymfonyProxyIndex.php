<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the Symfony CLI proxy's project index.
 *
 * The index is the one place that knows every local app's port without anyone copying it
 * into a tunnel config by hand -- which is how laptop-ssai.yml came to route "ddys" to 8014
 * while the proxy had long since given 8014 to rut.
 */
final class SymfonyProxyIndex
{
    /** @var array<string, ProxyProject>|null */
    private ?array $projects = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $indexUrl,
    ) {
    }

    /**
     * @return array<string, ProxyProject> keyed by absolute project directory
     */
    public function projects(): array
    {
        if (null !== $this->projects) {
            return $this->projects;
        }

        try {
            $data = $this->httpClient->request('GET', $this->indexUrl, ['timeout' => 1, 'max_duration' => 2])->toArray();
        } catch (ExceptionInterface) {
            // Proxy not running. Not an error: the app may pin its port in config instead.
            return $this->projects = [];
        }

        $projects = [];
        foreach ($data as $dir => $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $absolute = self::expandHome((string) $dir);
            $port = (int) ($entry['port'] ?? 0);
            $projects[$absolute] = new ProxyProject(
                dir: $absolute,
                // 0 is how the index says "no port assigned" -- the project is registered but
                // has never been pinned or started, so there is nothing to route to.
                port: $port > 0 ? $port : null,
                scheme: (string) ($entry['scheme'] ?? 'https'),
                domains: array_values(array_map('strval', (array) ($entry['domains'] ?? []))),
            );
        }

        return $this->projects = $projects;
    }

    public function forDir(string $projectDir): ?ProxyProject
    {
        $wanted = self::normalize($projectDir);
        foreach ($this->projects() as $dir => $project) {
            if (self::normalize($dir) === $wanted) {
                return $project;
            }
        }

        return null;
    }

    public function forApp(string $app): ?ProxyProject
    {
        foreach ($this->projects() as $project) {
            if ($project->app === $app) {
                return $project;
            }
        }

        return null;
    }

    public function forPort(int $port): ?ProxyProject
    {
        foreach ($this->projects() as $project) {
            if ($project->port === $port) {
                return $project;
            }
        }

        return null;
    }

    private static function expandHome(string $path): string
    {
        if (!str_starts_with($path, '~')) {
            return $path;
        }
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: (\function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');

        return rtrim((string) $home, '/').substr($path, 1);
    }

    /**
     * The index writes ~/sites/ssai; kernel.project_dir may say /Users/tac/Sites/ssai. APFS is
     * case-insensitive by default, so both are the same directory and a byte comparison would
     * quietly fail to find it.
     */
    private static function normalize(string $path): string
    {
        $path = rtrim(self::expandHome($path), '/');
        $path = realpath($path) ?: $path;

        return PHP_OS_FAMILY === 'Darwin' ? strtolower($path) : $path;
    }
}
