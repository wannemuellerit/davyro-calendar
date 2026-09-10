<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

interface BusyCalendarRegistry
{
    public function setEnabled(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $calendarId,
        bool $enabled,
    ): void;

    /** @return string[] */
    public function enabledCalendarIds(int $tenantId, int $userId, int $mailAccountId): array;
}
