<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle;

use Survos\CloudflaredBundle\EventListener\TunnelRequestListener;
use Survos\CloudflaredBundle\Service\CloudflaredAgent;
use Survos\CloudflaredBundle\Service\InstanceId;
use Survos\CloudflaredBundle\Service\SymfonyProxyIndex;
use Survos\CloudflaredBundle\Service\Tunnel;
use Survos\CloudflaredBundle\Service\TunnelEnvVarProcessor;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosCloudflaredBundle extends AbstractSurvosBundle
{
    protected function twigNamespace(): ?string
    {
        return null;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('enabled')
                    ->info('Resolve the tunnel and serve /_cloudflared/whoami. Default: on in the dev environment only, so prod never probes loopback ports.')
                    ->defaultNull()
                ->end()
                ->integerNode('port')
                    ->info('This app\'s local port. Default: looked up by project directory in the Symfony proxy index, which already knows the port pinned in .symfony.local.yaml.')
                    ->defaultNull()
                ->end()
                ->scalarNode('hostname')
                    ->info('Force the public hostname instead of finding it in the running tunnel\'s ingress. Rarely right: a pinned name is the hand-maintained value this bundle exists to remove.')
                    ->defaultNull()
                ->end()
                ->scalarNode('machine')
                    ->info('Hostname prefix for this machine (m4 → m4-<app>.<zone>). Per machine, not per app, hence an env var set once in the shell profile.')
                    ->defaultValue('%env(default::CLOUDFLARED_MACHINE)%')
                ->end()
                ->scalarNode('zone')
                    ->info('DNS zone the tunnel hostnames live in: dev-only survos.org (see README "Hostnames").')
                    ->defaultValue('survos.org')
                ->end()
                ->scalarNode('proxy_index')
                    ->info('Symfony CLI proxy project index.')
                    ->defaultValue('http://127.0.0.1:7080/index.json')
                ->end()
                ->arrayNode('metrics')
                    ->info('cloudflared metrics servers to look for running tunnels on. cloudflared takes the first free port of 20241-20245.')
                    ->scalarPrototype()->end()
                    ->defaultValue(array_map(static fn(int $p): string => 'http://127.0.0.1:' . $p, range(20241, 20245)))
                ->end()
                ->booleanNode('guard_debug_routes')
                    ->info('404 /_profiler and /_wdt, and drop X-Debug-Token, for requests that came through Cloudflare.')
                    ->defaultTrue()
                ->end()
            ->end();
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);

        $enabled = $config['enabled'] ?? ($builder->getParameter('kernel.environment') === 'dev');

        $services = $container->services()
            ->defaults()
                ->autowire()
                ->autoconfigure();

        $services->set(SymfonyProxyIndex::class)
            ->arg('$indexUrl', $config['proxy_index']);

        $services->set(CloudflaredAgent::class)
            ->arg('$metricsUrls', $config['metrics']);

        $services->set(InstanceId::class)
            ->arg('$file', '%kernel.project_dir%/var/cloudflared/instance');

        $services->set(Tunnel::class)
            ->arg('$projectDir', '%kernel.project_dir%')
            ->arg('$enabled', $enabled)
            ->arg('$zone', $config['zone'])
            ->arg('$port', $config['port'])
            ->arg('$hostname', $config['hostname'])
            ->arg('$machine', $config['machine'])
            ->public();

        $services->set(TunnelEnvVarProcessor::class);

        $services->set(TunnelRequestListener::class)
            ->arg('$app', basename((string) $builder->getParameter('kernel.project_dir')))
            ->arg('$enabled', $enabled)
            ->arg('$guardDebugRoutes', $config['guard_debug_routes']);
    }
}
