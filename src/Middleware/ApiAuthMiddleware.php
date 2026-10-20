<?php

declare(strict_types=1);

namespace AgenDAV\Middleware;

use AgenDAV\Davyro\DavyroSessionGuard;
use AgenDAV\UserContext;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class ApiAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->container->get('session');
        $username = (string) $session->get('username', '');
        if ($username === '' || (int) $session->get('davyro.tenant_id', 0) < 1) {
            return $this->json(['error' => ['code' => 'unauthenticated']], 401)
                ->withHeader('Cache-Control', 'no-store');
        }

        try {
            if (!$this->container->get(DavyroSessionGuard::class)->check()) {
                return $this->json(['error' => ['code' => 'unauthenticated']], 401)
                    ->withHeader('Cache-Control', 'no-store');
            }
        } catch (\Throwable) {
            return $this->json(['error' => ['code' => 'session_check_unavailable']], 503)
                ->withHeader('Cache-Control', 'no-store');
        }

        $preferences = $this->container->get('preferences.repository')->userPreferences($username);
        $this->container->get(UserContext::class)->setPreferences($preferences);
        $this->container->get(UserContext::class)->setTimezone((string) $preferences->get('timezone'));
        $this->container->get('translator')->setLocale((string) $preferences->get('language'));

        return $handler->handle($request);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
