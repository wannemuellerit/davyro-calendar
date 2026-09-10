<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;

interface WebCalFeedStateRepository
{
    public function find(int $tenantId, int $userId, string $subscriptionId): ?WebCalFeedState;

    public function save(WebCalFeedState $state): void;

    public function remove(WebCalFeedState $state): void;

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int;

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int;

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int;
}
