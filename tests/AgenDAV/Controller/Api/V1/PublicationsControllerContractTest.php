<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Data\CalendarPublication;
use AgenDAV\Data\Share;
use AgenDAV\Davyro\BrowserIdCodec;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\Publication\CalendarPublicationRepository;
use AgenDAV\Davyro\Publication\CalendarPublicationService;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\Repositories\SubscriptionsRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class PublicationsControllerContractTest extends TestCase
{
    private MailboxCalendarBindingsRepository $bindings;
    private CalendarAccess $access;
    private MemoryControllerPublicationRepository $publications;
    private CalendarPublicationService $service;
    private ContainerInterface $container;
    /** @var Share[] */
    private array $visibleShares = [];

    protected function setUp(): void
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
        $this->bindings = new MailboxCalendarBindingsRepository($db);
        $session = new Session(new MockArraySessionStorage());
        $session->set('username', 't1-u5');
        $session->set('davyro.tenant_id', 1);
        $session->set('davyro.tenant_prefix', 't1-');
        $session->set('davyro.user_id', 5);
        $session->set('davyro.active_mail_account_id', 10);
        $session->set('davyro.mailboxes', [['id' => 10, 'email' => 'owner@example.test']]);
        $session->set('principal_url', '/dav.php/principals/t1-u5/');
        $shares = $this->createMock(SharesRepository::class);
        $shares->method('getSharesFor')->willReturnCallback(fn (): array => $this->visibleShares);
        $this->access = new CalendarAccess(
            $session,
            $this->bindings,
            $shares,
            $this->createMock(SubscriptionsRepository::class),
            new BrowserIdCodec(str_repeat('contract-secret-', 3)),
        );
        $this->publications = new MemoryControllerPublicationRepository();
        $this->service = new CalendarPublicationService($this->publications);
        $services = [
            CalendarAccess::class => $this->access,
            CalendarPublicationService::class => $this->service,
            'davyro.calendar_url' => 'https://calendar.example.test/calendar-app',
            'environment' => 'prod',
        ];
        $this->container = new class($services) implements ContainerInterface {
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }

    public function testWritableSharedCalendarCannotBePublishedExternally(): void
    {
        $shared = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Shared calendar',
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($shared->calendarUrl());
        $share->setWritePermission(true);
        $this->visibleShares = [$share];

        $response = (new PublicationsController($this->container))->create(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/calendars/'.$shared->id().'/publications'),
            (new ResponseFactory())->createResponse(),
            ['id' => $shared->id()],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], $this->publications->all());
    }

    public function testPublicationCanOnlyBeRevokedThroughItsOwnCalendarRoute(): void
    {
        $first = $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Primary',
        );
        $second = $this->bindings->createAdditional(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10-extra',
            '/calendars/t1-u5/mailbox-10-extra/',
            'Extra',
            '#6875f5',
        );
        $issued = $this->service->create(1, 5, 10, $first->id());

        $response = (new PublicationsController($this->container))->revoke(
            (new ServerRequestFactory())->createServerRequest(
                'DELETE',
                '/api/v1/calendars/'.$second->id().'/publications/'.$issued->publication->getId(),
            ),
            (new ResponseFactory())->createResponse(),
            ['id' => $second->id(), 'publication_id' => $issued->publication->getId()],
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue($issued->publication->isActive());
    }
}

final class MemoryControllerPublicationRepository implements CalendarPublicationRepository
{
    /** @var array<string, CalendarPublication> */
    private array $items = [];

    public function save(CalendarPublication $publication): void
    {
        $this->items[$publication->getId()] = $publication;
    }

    public function findActiveByHash(string $tokenHash): ?CalendarPublication
    {
        foreach ($this->items as $item) {
            if ($item->isActive() && hash_equals($item->getTokenHash(), $tokenHash)) {
                return $item;
            }
        }

        return null;
    }

    public function findOwned(string $publicationId, int $tenantId, int $userId): ?CalendarPublication
    {
        $item = $this->items[$publicationId] ?? null;

        return $item !== null && $item->getTenantId() === $tenantId && $item->getUserId() === $userId
            ? $item
            : null;
    }

    public function findActiveForCalendar(string $calendarId, int $tenantId, int $userId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (CalendarPublication $item): bool => $item->isActive()
                && $item->getCalendarId() === $calendarId
                && $item->getTenantId() === $tenantId
                && $item->getUserId() === $userId,
        ));
    }

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): int
    {
        return 0;
    }

    /** @return array<string, CalendarPublication> */
    public function all(): array
    {
        return $this->items;
    }
}
