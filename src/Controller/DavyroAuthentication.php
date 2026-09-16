<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Davyro\DavyroSessionAuthenticator;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;

final class DavyroAuthentication
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $ticket = (string) ($query['ticket'] ?? '');

        try {
            $this->container->get(DavyroSessionAuthenticator::class)->authenticate(
                $ticket,
                ($query['embedded'] ?? '0') === '1'
            );

            /** @var RouteParserInterface $routeParser */
            $routeParser = $this->container->get(RouteParserInterface::class);
            return $response->withStatus(302)->withHeader('Location', $routeParser->urlFor('calendar'));
        } catch (\Throwable $exception) {
            $this->container->get('monolog')->warning('Davyro calendar SSO failed', [
                'reason' => $exception->getMessage(),
            ]);
            $response->getBody()->write('Der Kalender-Link ist ungültig oder abgelaufen. Bitte öffne den Kalender erneut über Davyro Mail.');
            return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }

}
