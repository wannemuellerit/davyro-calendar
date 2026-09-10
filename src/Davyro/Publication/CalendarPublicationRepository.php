<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Publication;

use AgenDAV\Data\CalendarPublication;

interface CalendarPublicationRepository
{
    public function save(CalendarPublication $publication): void;

    public function findActiveByHash(string $tokenHash): ?CalendarPublication;

    public function findOwned(string $publicationId, int $tenantId, int $userId): ?CalendarPublication;

    /** @return CalendarPublication[] */
    public function findActiveForCalendar(string $calendarId, int $tenantId, int $userId): array;

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int;

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int;

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int;
}
