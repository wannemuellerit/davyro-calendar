<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\Import\IcsImportCoordinator;
use AgenDAV\Davyro\Import\IcsImportIdentity;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

final class IcsImportsController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response): ResponseInterface {
            $input = $request->getParsedBody();
            $calendarId = trim((string) (is_array($input) ? ($input['calendar_id'] ?? '') : ''));
            $binding = $this->access()->bindingById($calendarId);
            if ($binding === null || !$binding->isWritable()) {
                throw new ApiNotFound();
            }
            $file = $request->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                throw new ApiValidation('file is required');
            }
            if (($file->getSize() ?? 0) > \AgenDAV\Davyro\Import\IcsImportService::MAX_BYTES) {
                throw new ApiValidation('ICS files must not be larger than 2 MiB');
            }
            $contents = (string) $file->getStream();
            $ticket = $this->coordinator()->preview(
                $this->identity($binding->mailAccountId()),
                $binding->id(),
                $binding->calendarUrl(),
                (string) ($file->getClientFilename() ?? 'calendar.ics'),
                (string) ($file->getClientMediaType() ?? 'application/octet-stream'),
                $contents,
            );

            return $this->json($response, $ticket->toArray(), 201);
        });
    }

    public function commit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response, $args): ResponseInterface {
            $calendarId = trim((string) ($args['id'] ?? ''));
            $binding = $this->access()->bindingById($calendarId);
            if ($binding === null || !$binding->isWritable()) {
                throw new ApiNotFound();
            }
            $input = $this->body($request);
            $result = $this->coordinator()->commit(
                $this->identity($binding->mailAccountId()),
                $calendarId,
                trim((string) ($input['import_token'] ?? '')),
                trim((string) ($input['duplicate_strategy'] ?? 'skip')),
            );

            return $this->json($response, [...$result->toArray(), 'errors' => []]);
        });
    }

    private function identity(int $mailAccountId): IcsImportIdentity
    {
        $session = $this->container->get('session');

        return new IcsImportIdentity(
            $this->access()->tenantId(),
            $this->access()->userId(),
            $mailAccountId,
            hash('sha256', (string) $session->getId()),
        );
    }

    private function access(): CalendarAccess
    {
        return $this->container->get(CalendarAccess::class);
    }

    private function coordinator(): IcsImportCoordinator
    {
        return $this->container->get(IcsImportCoordinator::class);
    }
}
