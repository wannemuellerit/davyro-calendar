<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use Doctrine\DBAL\Connection;

final readonly class DbalInternalImportTargetResolver implements InternalImportTargetResolver
{
    public function __construct(private Connection $connection)
    {
    }

    public function resolve(int $tenantId, int $userId, int $mailAccountId, string $calendarId): ?string
    {
        $url = $this->connection->fetchOne(
            'SELECT calendar_url FROM davyro_calendar_bindings '
            .'WHERE id = :id AND tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox '
            .'AND writable = 1 AND archived_at IS NULL',
            [
                'id' => $calendarId,
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
            ]
        );

        return is_string($url) && $url !== '' ? $url : null;
    }
}
