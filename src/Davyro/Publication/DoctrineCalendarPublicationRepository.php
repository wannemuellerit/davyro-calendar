<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCalendarPublicationRepository implements CalendarPublicationRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(CalendarPublication $publication): void
    {
        $this->entityManager->persist($publication);
        $this->entityManager->flush();
    }

    public function findActiveByHash(string $tokenHash): ?CalendarPublication
    {
        $id = $this->entityManager->getConnection()->fetchOne(
            'SELECT publication.id FROM davyro_calendar_publications publication '
            .'INNER JOIN davyro_calendar_bindings binding ON binding.id = publication.calendar_id '
            .'WHERE publication.token_hash = :hash AND publication.revoked_at IS NULL '
            .'AND publication.suspended_at IS NULL AND binding.archived_at IS NULL',
            ['hash' => $tokenHash]
        );

        return is_string($id) ? $this->entityManager->find(CalendarPublication::class, $id) : null;
    }

    public function findOwned(string $publicationId, int $tenantId, int $userId): ?CalendarPublication
    {
        return $this->entityManager->getRepository(CalendarPublication::class)->findOneBy([
            'id' => $publicationId,
            'tenantId' => $tenantId,
            'userId' => $userId,
        ]);
    }

    public function findActiveForCalendar(string $calendarId, int $tenantId, int $userId): array
    {
        return $this->entityManager->getRepository(CalendarPublication::class)->findBy([
            'calendarId' => $calendarId,
            'tenantId' => $tenantId,
            'userId' => $userId,
            'revokedAt' => null,
            'suspendedAt' => null,
        ]);
    }

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE davyro_calendar_publications SET suspended_at = :now '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox '
            .'AND revoked_at IS NULL AND suspended_at IS NULL',
            [
                'now' => gmdate('Y-m-d H:i:s'),
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
            ]
        );
    }

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'UPDATE davyro_calendar_publications SET suspended_at = NULL '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox '
            .'AND revoked_at IS NULL',
            ['tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId]
        );
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return $this->entityManager->getConnection()->executeStatement(
            'DELETE FROM davyro_calendar_publications '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox',
            ['tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId]
        );
    }
}
