<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

use AgenDAV\Data\MailboxAvailability;

interface MailboxAvailabilityRepository
{
    public function find(int $tenantId, int $userId, int $mailAccountId): ?MailboxAvailability;

    public function save(MailboxAvailability $availability): void;

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int;
}
