<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Data\MailboxAvailability;
use AgenDAV\Davyro\BrowserIdCodec;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\Repositories\SubscriptionsRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class AvailabilityControllerContractTest extends TestCase
{
    public function testBrowserDtoUsesPositiveAvailableFlag(): void
    {
        $access = $this->access();
        $container = new class($access) implements ContainerInterface {
            public function __construct(private readonly CalendarAccess $access)
            {
            }

            public function get(string $id): mixed
            {
                return $id === CalendarAccess::class ? $this->access : null;
            }

            public function has(string $id): bool
            {
                return $id === CalendarAccess::class;
            }
        };
        $availability = new MailboxAvailability(1, 5, 10, 'Europe/Berlin', [], [
            ['date' => '2026-12-24', 'unavailable' => true, 'windows' => []],
            ['date' => '2026-12-31', 'unavailable' => false, 'windows' => [['start' => '09:00', 'end' => '12:00']],],
        ]);

        $dto = (new \ReflectionMethod(AvailabilityController::class, 'dto'))
            ->invoke(new AvailabilityController($container), $availability);

        self::assertSame(false, $dto['exceptions'][0]['available']);
        self::assertSame(true, $dto['exceptions'][1]['available']);
        self::assertArrayNotHasKey('unavailable', $dto['exceptions'][0]);
        self::assertSame([['start' => '09:00', 'end' => '12:00']], $dto['exceptions'][1]['windows']);
    }

    private function access(): CalendarAccess
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement(<<<'SQL'
CREATE TABLE davyro_calendar_bindings (
 id VARCHAR(36) PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER,
 principal VARCHAR(191), calendar_uri VARCHAR(191), calendar_url TEXT, calendar_url_hash VARCHAR(64),
 name VARCHAR(160), color VARCHAR(9), kind VARCHAR(32), is_primary INTEGER, writable INTEGER,
 busy_enabled INTEGER, archived_at TEXT NULL, purge_after TEXT NULL, created_at TEXT, updated_at TEXT,
 UNIQUE (tenant_id, user_id, mail_account_id, principal, calendar_uri)
)
SQL);
        $session = new Session(new MockArraySessionStorage());
        $session->set('username', 't1-u5');
        $session->set('davyro.tenant_id', 1);
        $session->set('davyro.user_id', 5);
        $session->set('davyro.mailboxes', [['id' => 10, 'email' => 'owner@example.test']]);

        $bindings = new MailboxCalendarBindingsRepository($db);
        $bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/dav.php/calendars/t1-u5/mailbox-10/',
            'Primary'
        );

        return new CalendarAccess(
            $session,
            $bindings,
            $this->createMock(SharesRepository::class),
            $this->createMock(SubscriptionsRepository::class),
            new BrowserIdCodec(str_repeat('contract-secret-', 3)),
        );
    }
}
