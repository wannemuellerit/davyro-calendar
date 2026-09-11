<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Davyro\MailboxLifecycleGate;
use AgenDAV\Davyro\MailboxLifecycleUnavailable;
use AgenDAV\Davyro\Import\IcsImportCoordinator;
use AgenDAV\Davyro\Import\IcsImportIdentity;
use AgenDAV\Davyro\Import\InternalImportTargetResolver;
use AgenDAV\Davyro\Import\MailAttachmentImportRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InternalIcsImportsController extends ApiController
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function preview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response): ResponseInterface {
            $import = MailAttachmentImportRequest::fromArray($this->body($request));
            return $this->withActiveMailbox(
                $import->tenantId,
                $import->userId,
                $import->mailAccountId,
                $import->lifecycleVersion,
                function () use ($import, $response): ResponseInterface {
                    $calendarUrl = $this->resolver()->resolve(
                        $import->tenantId,
                        $import->userId,
                        $import->mailAccountId,
                        $import->calendarId,
                    ) ?? throw new ApiNotFound();
                    $ticket = $this->coordinator()->preview(
                        $this->identity($import->tenantId, $import->userId, $import->mailAccountId),
                        $import->calendarId,
                        $calendarUrl,
                        $import->filename,
                        $import->mimeType,
                        $import->contents,
                        $import->source(),
                    );

                    return $this->json($response, $ticket->toArray(), 201);
                }
            );
        });
    }

    public function commit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->guarded($response, function () use ($request, $response): ResponseInterface {
            $input = $this->body($request);
            $tenantId = (int) ($input['tenant_id'] ?? 0);
            $userId = (int) ($input['user_id'] ?? 0);
            $mailAccountId = (int) ($input['mail_account_id'] ?? 0);
            $lifecycleVersion = filter_var(
                $input['lifecycle_version'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            $calendarId = trim((string) ($input['target_calendar_id'] ?? ''));
            if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1 || $calendarId === '') {
                throw new ApiValidation('ICS attachment import metadata is incomplete');
            }
            if ($lifecycleVersion === false) {
                throw new ApiValidation('lifecycle_version must be a positive integer');
            }
            return $this->withActiveMailbox(
                $tenantId,
                $userId,
                $mailAccountId,
                $lifecycleVersion,
                function () use ($input, $response, $tenantId, $userId, $mailAccountId, $calendarId): ResponseInterface {
                    if ($this->resolver()->resolve($tenantId, $userId, $mailAccountId, $calendarId) === null) {
                        throw new ApiNotFound();
                    }
                    $result = $this->coordinator()->commit(
                        $this->identity($tenantId, $userId, $mailAccountId),
                        $calendarId,
                        trim((string) ($input['import_token'] ?? '')),
                        trim((string) ($input['duplicate_strategy'] ?? 'skip')),
                    );

                    return $this->json($response, [...$result->toArray(), 'errors' => []]);
                }
            );
        });
    }

    private function withActiveMailbox(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        int $lifecycleVersion,
        callable $operation,
    ): mixed {
        try {
            return $this->container->get(MailboxLifecycleGate::class)->run(
                $tenantId,
                $userId,
                [['id' => $mailAccountId, 'lifecycle_version' => $lifecycleVersion]],
                $operation
            );
        } catch (MailboxLifecycleUnavailable) {
            throw new ApiNotFound();
        }
    }

    private function identity(int $tenantId, int $userId, int $mailAccountId): IcsImportIdentity
    {
        return new IcsImportIdentity(
            $tenantId,
            $userId,
            $mailAccountId,
            'internal-mail-import',
        );
    }

    private function coordinator(): IcsImportCoordinator
    {
        return $this->container->get(IcsImportCoordinator::class);
    }

    private function resolver(): InternalImportTargetResolver
    {
        return $this->container->get(InternalImportTargetResolver::class);
    }
}
