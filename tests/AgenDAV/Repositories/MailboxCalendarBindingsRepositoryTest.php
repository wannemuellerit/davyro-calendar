<?php

declare(strict_types=1);

namespace AgenDAV\Repositories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class MailboxCalendarBindingsRepositoryTest extends TestCase
{
    private Connection $db;
    private MailboxCalendarBindingsRepository $repository;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement(<<<'SQL'
CREATE TABLE davyro_calendar_bindings (
    id VARCHAR(36) PRIMARY KEY,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    mail_account_id INTEGER NOT NULL,
    principal VARCHAR(191) NOT NULL,
    calendar_uri VARCHAR(191) NOT NULL,
    calendar_url TEXT NOT NULL,
    calendar_url_hash VARCHAR(64) NOT NULL,
    name VARCHAR(160) NOT NULL,
    color VARCHAR(9) NOT NULL,
    kind VARCHAR(32) NOT NULL,
    is_primary INTEGER NOT NULL,
    writable INTEGER NOT NULL,
    busy_enabled INTEGER NOT NULL,
    archived_at TEXT NULL,
    purge_after TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (tenant_id, user_id, mail_account_id, principal, calendar_uri)
)
SQL);
        $this->repository = new MailboxCalendarBindingsRepository($this->db);
    }

    public function testPrimaryProvisioningIsIdempotentAndUsesOpaqueId(): void
    {
        $first = $this->primary();
        $second = $this->repository->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            'https://calendar.invalid/dav.php/calendars/t1-u5/mailbox-10/',
            'Neuer Name'
        );

        $this->assertSame($first->id(), $second->id());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first->id());
        $this->assertSame('Neuer Name', $second->name());
        $this->assertSame('/dav.php/calendars/t1-u5/mailbox-10/', $second->calendarUrl());
        $this->assertTrue($second->isPrimary());
    }

    public function testOpaqueLookupCannotCrossTenantOrMailboxBoundary(): void
    {
        $binding = $this->primary();

        $this->assertNotNull($this->repository->findVisibleById($binding->id(), 1, 5, [10]));
        $this->assertNull($this->repository->findVisibleById($binding->id(), 2, 5, [10]));
        $this->assertNull($this->repository->findVisibleById($binding->id(), 1, 5, [11]));
        $this->assertNull($this->repository->findVisibleById('mailbox-10', 1, 5, [10]));
    }

    public function testRepairReusesTheExistingPrimaryBindingWhenItsUriChanged(): void
    {
        $this->db->insert('davyro_calendar_bindings', [
            'id' => '8b14033e-c408-4f3f-9654-268db8689e30',
            'tenant_id' => 1,
            'user_id' => 5,
            'mail_account_id' => 10,
            'principal' => 't1-u5',
            'calendar_uri' => 'legacy-primary',
            'calendar_url' => '/dav.php/calendars/t1-u5/legacy-primary/',
            'calendar_url_hash' => hash('sha256', '/dav.php/calendars/t1-u5/legacy-primary/'),
            'name' => 'Alter Kalendername',
            'color' => '#6875f5',
            'kind' => 'primary',
            'is_primary' => 1,
            'writable' => 1,
            'busy_enabled' => 1,
            'archived_at' => null,
            'purge_after' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $repaired = $this->repository->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/dav.php/calendars/t1-u5/mailbox-10/',
            'Kalender · renamed@example.test'
        );

        self::assertSame('8b14033e-c408-4f3f-9654-268db8689e30', $repaired->id());
        self::assertSame('mailbox-10', $repaired->calendarUri());
        self::assertSame('Kalender · renamed@example.test', $repaired->name());
        self::assertCount(1, $this->repository->findForMailbox(1, 5, 10));
    }

    public function testArchiveRestoreAndRetentionGate(): void
    {
        $binding = $this->primary();
        $archived = $this->repository->archiveMailbox(
            1,
            5,
            10,
            't1-u5',
            new \DateTimeImmutable('2099-01-01T00:00:00Z')
        );

        $this->assertCount(1, $archived);
        $this->assertNotNull($archived[0]->archivedAt());
        $this->assertNotNull($archived[0]->purgeAfter());
        $this->assertSame(30, $archived[0]->archivedAt()->diff($archived[0]->purgeAfter())->days);
        $this->assertNull($this->repository->findVisibleById($binding->id(), 1, 5, [10]));
        $this->assertSame([], $this->repository->findPurgeableMailbox(1, 5, 10, 't1-u5'));

        $restored = $this->repository->restoreMailbox(1, 5, 10, 't1-u5');
        $this->assertNull($restored[0]->archivedAt());
        $this->assertNotNull($this->repository->findVisibleById($binding->id(), 1, 5, [10]));
    }

    public function testArchiveRecoveryNeverShortensAnExistingLaterDeadline(): void
    {
        $binding = $this->primary();
        $this->db->update('davyro_calendar_bindings', [
            'purge_after' => '2099-01-01 00:00:00',
        ], ['id' => $binding->id()]);

        $archived = $this->repository->archiveMailbox(
            1,
            5,
            10,
            't1-u5',
            new \DateTimeImmutable('2000-01-01T00:00:00Z')
        );

        self::assertCount(1, $archived);
        self::assertSame('2099-01-01T00:00:00+00:00', $archived[0]->purgeAfter()?->format(DATE_ATOM));
        self::assertSame(30, $archived[0]->archivedAt()?->diff($archived[0]->purgeAfter())->days);
        self::assertSame([], $this->repository->findPurgeableMailbox(1, 5, 10, 't1-u5'));
    }

    public function testArchiveRetryExtendsAnAlreadyArchivedMailboxToTheNewAcknowledgedDeadline(): void
    {
        $this->primary();
        $this->repository->archiveMailbox(
            1,
            5,
            10,
            't1-u5',
            new \DateTimeImmutable('2099-01-01T00:00:00Z')
        );

        $retried = $this->repository->archiveMailbox(
            1,
            5,
            10,
            't1-u5',
            new \DateTimeImmutable('2099-02-01T00:00:00Z')
        );

        self::assertCount(1, $retried);
        self::assertSame('2099-02-01T00:00:00+00:00', $retried[0]->purgeAfter()?->format(DATE_ATOM));
        self::assertSame(30, $retried[0]->archivedAt()?->diff($retried[0]->purgeAfter())->days);
    }

    public function testAdditionalCalendarIsBoundToItsMailboxAndCanBeDeleted(): void
    {
        $this->primary();
        $additional = $this->repository->createAdditional(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10-work',
            '/dav.php/calendars/t1-u5/mailbox-10-work/',
            'Arbeit',
            '#123456'
        );

        $this->assertFalse($additional->isPrimary());
        $this->assertSame(10, $additional->mailAccountId());
        $this->assertCount(2, $this->repository->findForMailbox(1, 5, 10));

        $this->repository->deleteAdditional($additional);
        $this->assertCount(1, $this->repository->findForMailbox(1, 5, 10));
    }

    private function primary(): \AgenDAV\Data\MailboxCalendarBinding
    {
        return $this->repository->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/dav.php/calendars/t1-u5/mailbox-10/',
            'Kalender · test@example.com'
        );
    }
}
