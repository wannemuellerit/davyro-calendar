<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Davyro\DavyroSessionAuthenticator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SessionController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $input = $this->body($request);
            $ticket = trim((string) ($input['ticket'] ?? ''));
            if (preg_match('/^[A-Za-z0-9]{64}$/', $ticket) !== 1) {
                throw new \InvalidArgumentException('A valid one-time ticket is required');
            }
            $this->container->get(DavyroSessionAuthenticator::class)->authenticate(
                $ticket,
                (bool) ($input['embedded'] ?? true)
            );

            return $this->json($response, [
                'data' => ['authenticated' => true],
                'csrf_token' => $this->container->get('csrf.manager')
                    ->getToken($this->container->get('csrf.secret'))->getValue(),
            ], 201);
        } catch (\Throwable $exception) {
            $this->container->get('monolog')->warning('Davyro API session failed', [
                'reason' => $exception->getMessage(),
            ]);

            return $this->error($response, 'invalid_ticket', 'The calendar ticket is invalid or expired', 403);
        }
    }
}
