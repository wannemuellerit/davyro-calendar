<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\Publication\CalendarPublicationService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PublicationsController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            $binding = $this->binding((string) ($args['id'] ?? ''));
            $items = array_map(static fn (\AgenDAV\Data\CalendarPublication $publication): array => [
                'id' => $publication->getId(),
                'calendar_id' => $publication->getCalendarId(),
                'created_at' => $publication->getCreatedAt()->format(DATE_ATOM),
                'url_available' => false,
            ], $this->service()->listActive($binding->id(), $this->access()->tenantId(), $this->access()->userId()));

            return $this->json($response, ['data' => $items]);
        });
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            return $this->access()->withActiveBinding(
                (string) ($args['id'] ?? ''),
                true,
                function ($binding) use ($response): ResponseInterface {
                    $this->assertOwnedBinding($binding);
                    $issued = $this->service()->create(
                        $this->access()->tenantId(),
                        $this->access()->userId(),
                        $binding->mailAccountId(),
                        $binding->id(),
                    );

                    return $this->json($response, ['data' => $this->dto($issued)], 201);
                }
            );
        });
    }

    public function rotate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            return $this->access()->withActiveBinding(
                (string) ($args['id'] ?? ''),
                true,
                function ($binding) use ($response): ResponseInterface {
                    $this->assertOwnedBinding($binding);
                    $issued = $this->service()->rotate(
                        $this->access()->tenantId(),
                        $this->access()->userId(),
                        $binding->mailAccountId(),
                        $binding->id(),
                    );

                    return $this->json($response, ['data' => $this->dto($issued)], 201);
                }
            );
        });
    }

    public function revoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($response, $args): ResponseInterface {
            return $this->access()->withActiveBinding(
                (string) ($args['id'] ?? ''),
                true,
                function ($binding) use ($response, $args): ResponseInterface {
                    $this->assertOwnedBinding($binding);
                    $publicationId = trim((string) ($args['publication_id'] ?? ''));
                    $belongsToCalendar = false;
                    foreach ($this->service()->listActive(
                        $binding->id(),
                        $this->access()->tenantId(),
                        $this->access()->userId()
                    ) as $publication) {
                        if (hash_equals($publication->getId(), $publicationId)) {
                            $belongsToCalendar = true;
                            break;
                        }
                    }
                    if (!$belongsToCalendar) {
                        throw new ApiNotFound();
                    }
                    if (!$this->service()->revoke(
                        $publicationId,
                        $this->access()->tenantId(),
                        $this->access()->userId()
                    )) {
                        throw new ApiNotFound();
                    }

                    return $response->withStatus(204);
                }
            );
        });
    }

    private function binding(string $id): \AgenDAV\Data\MailboxCalendarBinding
    {
        $binding = $this->access()->bindingById($id);
        if ($binding === null) {
            throw new ApiNotFound();
        }
        $this->assertOwnedBinding($binding);

        return $binding;
    }

    private function assertOwnedBinding(\AgenDAV\Data\MailboxCalendarBinding $binding): void
    {
        if ($binding->kind() === \AgenDAV\Data\MailboxCalendarBinding::KIND_SHARED
            || $this->access()->ownedBindingByUrl($binding->calendarUrl()) === null
            || !$binding->isWritable()
        ) {
            throw new ApiNotFound();
        }
    }

    /** @return array<string, mixed> */
    private function dto(\AgenDAV\Davyro\Publication\IssuedPublication $issued): array
    {
        $configuredBase = rtrim((string) $this->container->get('davyro.calendar_url'), '/');
        $parts = parse_url($configuredBase);
        $environment = (string) $this->container->get('environment');
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || ($environment !== 'dev' && strtolower((string) $parts['scheme']) !== 'https')) {
            throw new \RuntimeException('A trusted HTTPS Davyro calendar URL is required for public links');
        }
        $path = '/public/calendars/'.$issued->token.'.ics';
        $httpsUrl = $configuredBase.$path;
        $authority = (string) $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $basePath = rtrim((string) ($parts['path'] ?? ''), '/');
        $webcalUrl = 'webcal://'.$authority.$basePath.$path;

        return [
            'id' => $issued->publication->getId(),
            'calendar_id' => $issued->publication->getCalendarId(),
            'https_url' => $httpsUrl,
            'webcal_url' => $webcalUrl,
            'created_at' => $issued->publication->getCreatedAt()->format(DATE_ATOM),
            'warning' => 'Jeder Besitzer dieses Links kann die freigegebenen Termine lesen.',
        ];
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function service(): CalendarPublicationService
    {
        return $this->container->get(CalendarPublicationService::class);
    }
}
