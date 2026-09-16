<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Davyro\Publication\CalendarPublicationRenderer;
use AgenDAV\Davyro\Publication\CalendarPublicationService;
use AgenDAV\Davyro\Publication\PublishedCalendarSource;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PublishedCalendarController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = (string) ($args['token'] ?? '');
        $publication = $this->container->get(CalendarPublicationService::class)->resolve($token);
        if ($publication === null) {
            return $this->notFound($response);
        }

        try {
            $source = $this->container->get(PublishedCalendarSource::class)->export($publication);
            $contents = $this->container->get(CalendarPublicationRenderer::class)->render($source);
        } catch (\Throwable $exception) {
            $this->container->get('monolog')->warning('Published calendar export failed', [
                'publication_id' => $publication->getId(),
                'reason' => $exception->getMessage(),
            ]);

            return $this->notFound($response);
        }

        $response->getBody()->write($contents);

        return $response
            ->withHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="calendar.ics"')
            ->withHeader('Cache-Control', 'private, no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write('Kalender nicht gefunden');

        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }
}
