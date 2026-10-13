<?php

declare(strict_types=1);

namespace Survos\CloudflaredBundle\EventListener;

use Survos\CloudflaredBundle\Service\InstanceId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Two jobs for requests that arrive through the tunnel.
 *
 * 1. /_cloudflared/whoami answers with this checkout's InstanceId, so `cloudflared:probe` can
 *    tell whether the public hostname really comes back here. It runs ahead of the router and
 *    the firewall (priority 256) so it needs no route import and no access_control entry.
 *
 * 2. A tunnel publishes a DEV-mode app to the internet, and in dev /_profiler is a full
 *    dump of every request -- server parameters, env vars, API keys. Requests carrying
 *    Cloudflare's headers get a 404 for /_profiler and /_wdt, and their responses lose
 *    X-Debug-Token, which both stops the toolbar injecting (WebDebugToolbarListener bails
 *    without it) and stops the token link leaking. Local requests are untouched.
 */
final class TunnelRequestListener
{
    public const WHOAMI_PATH = '/_cloudflared/whoami';

    public function __construct(
        private readonly InstanceId $instanceId,
        private readonly string $app,
        private readonly bool $enabled,
        private readonly bool $guardDebugRoutes,
    ) {
    }

    /**
     * Cloudflare adds these to every request it forwards to the origin. A local client could
     * send them too, but all that buys it is hiding its own profiler from itself.
     */
    public static function viaTunnel(Request $request): bool
    {
        return $request->headers->has('cf-ray') || $request->headers->has('cf-connecting-ip');
    }

    #[AsEventListener(KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if ($this->enabled && self::WHOAMI_PATH === $path) {
            $response = new JsonResponse([
                'app' => $this->app,
                'instance' => $this->instanceId->get(),
                'viaTunnel' => self::viaTunnel($request),
                'host' => $request->getHost(),
            ]);
            $response->headers->set('Cache-Control', 'no-store');
            $event->setResponse($response);

            return;
        }

        if ($this->guardDebugRoutes && self::viaTunnel($request)
            && (str_starts_with($path, '/_profiler') || str_starts_with($path, '/_wdt'))) {
            $event->setResponse(new Response('Not Found', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain']));
        }
    }

    /** After ProfilerListener (-1024) sets the token, before WebDebugToolbarListener (-2048) reads it. */
    #[AsEventListener(KernelEvents::RESPONSE, priority: -1536)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$this->guardDebugRoutes || !self::viaTunnel($event->getRequest())) {
            return;
        }

        $event->getResponse()->headers->remove('X-Debug-Token');
        $event->getResponse()->headers->remove('X-Debug-Token-Link');
    }
}
