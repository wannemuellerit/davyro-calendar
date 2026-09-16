<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Import;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class DbalInternalImportTargetResolverTest extends TestCase
{
    public function testResolveRequiresTheExactActiveWritableTenantUserAndMailboxTuple(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement(<<<'SQL'
CREATE TABLE davyro_calendar_bindings (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    mail_account_id INTEGER NOT NULL,
    calendar_url TEXT NOT NULL,
    writable INTEGER NOT NULL,
    archived_at TEXT NULL
)
SQL);
        $db->insert('davyro_calendar_bindings', [
            'id' => 'calendar-a',
            'tenant_id' => 7,
            'user_id' => 11,
            'mail_account_id' => 13,
            'calendar_url' => '/dav.php/calendars/t7-u11/mailbox-13/',
            'writable' => 1,
            'archived_at' => null,
        ]);

        $resolver = new DbalInternalImportTargetResolver($db);

        self::assertSame(
            '/dav.php/calendars/t7-u11/mailbox-13/',
            $resolver->resolve(7, 11, 13, 'calendar-a'),
        );
        self::assertNull($resolver->resolve(8, 11, 13, 'calendar-a'), 'Cross-tenant lookup must fail');
        self::assertNull($resolver->resolve(7, 12, 13, 'calendar-a'), 'Cross-user lookup must fail');
        self::assertNull($resolver->resolve(7, 11, 14, 'calendar-a'), 'Cross-mailbox lookup must fail');
        self::assertNull($resolver->resolve(7, 11, 13, 'calendar-b'), 'Unknown calendar id must fail');

        $db->update('davyro_calendar_bindings', ['writable' => 0], ['id' => 'calendar-a']);
        self::assertNull($resolver->resolve(7, 11, 13, 'calendar-a'), 'Read-only target must fail');

        $db->update('davyro_calendar_bindings', [
            'writable' => 1,
            'archived_at' => '2026-09-10 20:00:00',
        ], ['id' => 'calendar-a']);
        self::assertNull($resolver->resolve(7, 11, 13, 'calendar-a'), 'Archived target must fail');
    }
}
