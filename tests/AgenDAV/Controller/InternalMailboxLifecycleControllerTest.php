<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Davyro\Availability\MailboxAvailabilityRepository;
use AgenDAV\Davyro\BaikalPrincipalProvisioner;
use AgenDAV\Davyro\Publication\CalendarPublicationRepository;
use AgenDAV\Davyro\WebCal\WebCalFeedStateRepository;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InternalMailboxLifecycleControllerTest extends TestCase
{
    public function testOlderLifecycleOperationIsIgnoredAfterNewerVersion(): void
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
        $db->executeStatement(<<<'SQL'
CREATE TABLE davyro_mailbox_lifecycle_state (
 tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER, principal VARCHAR(191),
 lifecycle_version INTEGER, last_action VARCHAR(16), updated_at TEXT,
 PRIMARY KEY (tenant_id, user_id, mail_account_id)
)
SQL);
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $services = [
            'db' => $db,
            'monolog' => $logger,
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => new MailboxCalendarBindingsRepository($db),
        ];
        $container = new class($services) implements ContainerInterface {
            public function __construct(private array $services)
            {
            }
            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException('Unknown service '.$id);
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
        $controller = new InternalMailboxLifecycleController($container);
        $factory = new ServerRequestFactory();
        $newer = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/archive');
        $newer->getBody()->write(json_encode($this->payload(2, ['purge_after' => '2099-01-01T00:00:00Z'])));
        $newerResult = $controller($newer, (new ResponseFactory())->createResponse(), ['action' => 'archive']);

        $older = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/restore');
        $older->getBody()->write(json_encode($this->payload(1)));
        $olderResult = $controller($older, (new ResponseFactory())->createResponse(), ['action' => 'restore']);

        $this->assertSame(200, $newerResult->getStatusCode());
        $this->assertLifecycleAck($newerResult, 3, 2, 'archive', 'absent');
        $this->assertSame(200, $olderResult->getStatusCode());
        $this->assertLifecycleAck($olderResult, 3, 1, 'restore', 'stale_ignored');
        $this->assertSame(
            2,
            json_decode((string) $olderResult->getBody(), true)['data']['current_lifecycle_version']
        );
        $this->assertSame(2, (int) $db->fetchOne('SELECT lifecycle_version FROM davyro_mailbox_lifecycle_state'));
        $this->assertSame('archive', $db->fetchOne('SELECT last_action FROM davyro_mailbox_lifecycle_state'));
    }

    public function testIdenticalLifecycleRetryIsIdempotentButSameVersionDifferentActionFailsClosed(): void
    {
        $db = $this->lifecycleDatabase();
        $bindings = new MailboxCalendarBindingsRepository($db);
        $bindings->ensurePrimary(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3',
            '/dav.php/calendars/t1-u2/mailbox-3/',
            'Primary'
        );
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => $bindings,
        ]));
        $factory = new ServerRequestFactory();
        $payload = $this->payload(4, ['purge_after' => '2099-01-01T00:00:00Z']);

        foreach ([1, 2] as $attempt) {
            $request = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/archive');
            $request->getBody()->write(json_encode($payload));
            $response = $controller(
                $request,
                (new ResponseFactory())->createResponse(),
                ['action' => 'archive']
            );

            self::assertSame(200, $response->getStatusCode(), 'Retry '.$attempt.' must remain idempotent');
            $this->assertLifecycleAck($response, 3, 4, 'archive', 'archived');
        }

        $conflict = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/restore');
        $conflict->getBody()->write(json_encode($this->payload(4)));
        $conflictResponse = $controller(
            $conflict,
            (new ResponseFactory())->createResponse(),
            ['action' => 'restore']
        );

        self::assertSame(422, $conflictResponse->getStatusCode());
        self::assertSame('invalid_request', json_decode(
            (string) $conflictResponse->getBody(),
            true
        )['error']['code']);
        self::assertSame('archive', $db->fetchOne('SELECT last_action FROM davyro_mailbox_lifecycle_state'));
        self::assertNotNull($bindings->findForMailbox(1, 2, 3, true)[0]->archivedAt());
    }

    public function testArchiveAndPurgeRequireSignedRetentionDeadline(): void
    {
        $db = $this->lifecycleDatabase();
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => new MailboxCalendarBindingsRepository($db),
        ]));
        $factory = new ServerRequestFactory();

        foreach (['archive', 'purge'] as $action) {
            $request = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/'.$action);
            $request->getBody()->write(json_encode($this->payload(1)));
            $response = $controller(
                $request,
                (new ResponseFactory())->createResponse(),
                ['action' => $action]
            );

            self::assertSame(422, $response->getStatusCode());
            self::assertSame('invalid_request', json_decode(
                (string) $response->getBody(),
                true
            )['error']['code']);
        }
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM davyro_mailbox_lifecycle_state'));
    }

    public function testArchiveAndRestoreKeepAllCalendarIdsAndSuspendMailboxMetadata(): void
    {
        $db = $this->lifecycleDatabase();
        $bindings = new MailboxCalendarBindingsRepository($db);
        $primary = $bindings->ensurePrimary(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3',
            '/dav.php/calendars/t1-u2/mailbox-3/',
            'Primary'
        );
        $additional = $bindings->createAdditional(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3-team',
            '/dav.php/calendars/t1-u2/mailbox-3-team/',
            'Team',
            '#123456'
        );
        $publications = $this->createMock(CalendarPublicationRepository::class);
        $publications->expects(self::once())->method('archiveMailbox')->with(1, 2, 3)->willReturn(1);
        $publications->expects(self::once())->method('restoreMailbox')->with(1, 2, 3)->willReturn(1);
        $feeds = $this->createMock(WebCalFeedStateRepository::class);
        $feeds->expects(self::once())->method('archiveMailbox')->with(1, 2, 3)->willReturn(1);
        $feeds->expects(self::once())->method('restoreMailbox')->with(1, 2, 3)->willReturn(1);
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => $bindings,
            CalendarPublicationRepository::class => $publications,
            WebCalFeedStateRepository::class => $feeds,
        ]));
        $factory = new ServerRequestFactory();
        $archive = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/archive');
        $archive->getBody()->write(json_encode($this->payload(1, [
            'purge_after' => '2026-10-11T10:15:30+00:00',
        ])));

        $archivedResponse = $controller(
            $archive,
            (new ResponseFactory())->createResponse(),
            ['action' => 'archive']
        );

        self::assertSame(200, $archivedResponse->getStatusCode());
        $this->assertLifecycleAck($archivedResponse, 3, 1, 'archive', 'archived');
        $archived = $bindings->findForMailbox(1, 2, 3, true);
        self::assertCount(2, $archived);
        self::assertEqualsCanonicalizing([$primary->id(), $additional->id()], array_map(
            static fn ($binding): string => $binding->id(),
            $archived
        ));
        foreach ($archived as $binding) {
            self::assertNotNull($binding->archivedAt());
            self::assertNotNull($binding->purgeAfter());
            self::assertSame(30, $binding->archivedAt()->diff($binding->purgeAfter())->days);
            self::assertSame('2026-10-11T10:15:30+00:00', $binding->purgeAfter()->format(DATE_ATOM));
        }

        $restore = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/restore');
        $restore->getBody()->write(json_encode($this->payload(2)));
        $restoredResponse = $controller(
            $restore,
            (new ResponseFactory())->createResponse(),
            ['action' => 'restore']
        );

        self::assertSame(200, $restoredResponse->getStatusCode());
        $this->assertLifecycleAck($restoredResponse, 3, 2, 'restore', 'active');
        $restored = $bindings->findForMailbox(1, 2, 3);
        self::assertCount(2, $restored);
        self::assertEqualsCanonicalizing([$primary->id(), $additional->id()], array_map(
            static fn ($binding): string => $binding->id(),
            $restored
        ));
    }

    public function testExpiredMailboxPurgeRemovesOnlyPersistentlyBoundCalendarsAndMetadata(): void
    {
        $db = $this->lifecycleDatabase();
        $db->executeStatement('CREATE TABLE subscriptions (sid VARCHAR(36), owner TEXT, options TEXT)');
        $db->executeStatement('CREATE TABLE shares (sid VARCHAR(36), calendar TEXT)');
        $bindings = new MailboxCalendarBindingsRepository($db);
        $primary = $bindings->ensurePrimary(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3',
            '/dav.php/calendars/t1-u2/mailbox-3/',
            'Primary'
        );
        $additional = $bindings->createAdditional(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3-team',
            '/dav.php/calendars/t1-u2/mailbox-3-team/',
            'Team',
            '#123456'
        );
        $db->insert('subscriptions', [
            'sid' => 'target-subscription',
            'owner' => '/dav.php/principals/t1-u2/',
            'options' => json_encode(['davyro.mail_account_id' => 3]),
        ]);
        $db->insert('subscriptions', [
            'sid' => 'other-subscription',
            'owner' => '/dav.php/principals/t1-u2/',
            'options' => json_encode(['davyro.mail_account_id' => 4]),
        ]);
        $db->insert('shares', ['sid' => 'target-share', 'calendar' => $additional->calendarUrl()]);
        $db->insert('shares', ['sid' => 'other-share', 'calendar' => '/dav.php/calendars/t1-u2/other/']);

        $publications = $this->createMock(CalendarPublicationRepository::class);
        $publications->expects(self::once())->method('archiveMailbox')->with(1, 2, 3)->willReturn(1);
        $publications->expects(self::once())->method('purgeMailbox')->with(1, 2, 3)->willReturn(1);
        $feeds = $this->createMock(WebCalFeedStateRepository::class);
        $feeds->expects(self::once())->method('archiveMailbox')->with(1, 2, 3)->willReturn(1);
        $feeds->expects(self::once())->method('purgeMailbox')->with(1, 2, 3)->willReturn(1);
        $availability = $this->createMock(MailboxAvailabilityRepository::class);
        $availability->expects(self::once())->method('purgeMailbox')->with(1, 2, 3)->willReturn(1);
        $baikal = $this->baikalDatabase();
        $provisioner = new BaikalPrincipalProvisioner($baikal, str_repeat('secret', 8));
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => $bindings,
            CalendarPublicationRepository::class => $publications,
            WebCalFeedStateRepository::class => $feeds,
            MailboxAvailabilityRepository::class => $availability,
            BaikalPrincipalProvisioner::class => $provisioner,
        ]));
        $factory = new ServerRequestFactory();
        $archive = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/archive');
        $archive->getBody()->write(json_encode($this->payload(1, [
            'purge_after' => '2000-01-01T00:00:00Z',
        ])));
        self::assertSame(200, $controller(
            $archive,
            (new ResponseFactory())->createResponse(),
            ['action' => 'archive']
        )->getStatusCode());
        $purge = $factory->createServerRequest('POST', '/internal/davyro/mailboxes/purge');
        $purge->getBody()->write(json_encode($this->payload(2, [
            'purge_after' => '2000-01-01T00:00:00Z',
        ])));

        $purgedResponse = $controller(
            $purge,
            (new ResponseFactory())->createResponse(),
            ['action' => 'purge']
        );

        self::assertSame(200, $purgedResponse->getStatusCode());
        $this->assertLifecycleAck($purgedResponse, 3, 2, 'purge', 'purged');
        self::assertSame($primary->id(), json_decode((string) $purgedResponse->getBody(), true)['data']['calendar_id']);
        self::assertSame([], $bindings->findForMailbox(1, 2, 3, true));
        self::assertSame(['other-subscription'], $db->fetchFirstColumn('SELECT sid FROM subscriptions ORDER BY sid'));
        self::assertSame(['other-share'], $db->fetchFirstColumn('SELECT sid FROM shares ORDER BY sid'));
        self::assertSame([3], array_map(
            'intval',
            $baikal->query('SELECT id FROM calendars ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)
        ));
        self::assertSame(['mailbox-3-unbound'], $baikal->query(
            'SELECT uri FROM calendarinstances WHERE access = 1 ORDER BY uri'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testExpiredPurgeRecoversWhenArchiveDeliveryWasSkipped(): void
    {
        $db = $this->lifecycleDatabase();
        $bindings = new MailboxCalendarBindingsRepository($db);
        $primary = $bindings->ensurePrimary(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3',
            '/dav.php/calendars/t1-u2/mailbox-3/',
            'Primary'
        );
        $baikal = $this->baikalDatabase();
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => $bindings,
            BaikalPrincipalProvisioner::class => new BaikalPrincipalProvisioner($baikal, str_repeat('secret', 8)),
        ]));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/internal/davyro/mailboxes/purge');
        $request->getBody()->write(json_encode($this->payload(7, [
            'purge_after' => '2000-01-01T00:00:00Z',
        ])));

        $response = $controller(
            $request,
            (new ResponseFactory())->createResponse(),
            ['action' => 'purge']
        );

        self::assertSame(200, $response->getStatusCode());
        $this->assertLifecycleAck($response, 3, 7, 'purge', 'purged');
        self::assertSame($primary->id(), json_decode((string) $response->getBody(), true)['data']['calendar_id']);
        self::assertSame([], $bindings->findForMailbox(1, 2, 3, true));
        self::assertSame(['mailbox-3-team', 'mailbox-3-unbound'], $baikal->query(
            'SELECT uri FROM calendarinstances WHERE access = 1 ORDER BY uri'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testPurgeIsRejectedWhileThirtyDayRetentionIsActive(): void
    {
        $db = $this->lifecycleDatabase();
        $bindings = new MailboxCalendarBindingsRepository($db);
        $primary = $bindings->ensurePrimary(
            1,
            2,
            3,
            't1-u2',
            'mailbox-3',
            '/dav.php/calendars/t1-u2/mailbox-3/',
            'Primary'
        );
        $bindings->archiveMailbox(
            1,
            2,
            3,
            't1-u2',
            new \DateTimeImmutable('2099-01-01T00:00:00Z')
        );
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => $bindings,
        ]));
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/internal/davyro/mailboxes/purge');
        $request->getBody()->write(json_encode($this->payload(1, [
            'purge_after' => '2000-01-01T00:00:00Z',
        ])));

        $response = $controller(
            $request,
            (new ResponseFactory())->createResponse(),
            ['action' => 'purge']
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('retention_active', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertSame($primary->id(), $bindings->findForMailbox(1, 2, 3, true)[0]->id());
    }

    public function testAbsentBindingPurgeCleansDeterministicOrphanOnlyAfterRetention(): void
    {
        $db = $this->lifecycleDatabase();
        $bindings = new MailboxCalendarBindingsRepository($db);
        $baikal = $this->baikalDatabase();
        $controller = new InternalMailboxLifecycleController($this->container([
            'db' => $db,
            'monolog' => $this->logger(),
            'caldav.baseurl' => '/dav.php/',
            MailboxCalendarBindingsRepository::class => $bindings,
            BaikalPrincipalProvisioner::class => new BaikalPrincipalProvisioner($baikal, str_repeat('secret', 8)),
        ]));
        $future = (new ServerRequestFactory())->createServerRequest('POST', '/internal/davyro/mailboxes/purge');
        $future->getBody()->write(json_encode($this->payload(1, [
            'purge_after' => '2099-01-01T00:00:00Z',
        ])));

        $futureResponse = $controller(
            $future,
            (new ResponseFactory())->createResponse(),
            ['action' => 'purge']
        );

        self::assertSame(409, $futureResponse->getStatusCode());
        self::assertSame(['mailbox-3', 'mailbox-3-team', 'mailbox-3-unbound'], $baikal->query(
            'SELECT uri FROM calendarinstances WHERE access = 1 ORDER BY uri'
        )->fetchAll(PDO::FETCH_COLUMN));

        $due = (new ServerRequestFactory())->createServerRequest('POST', '/internal/davyro/mailboxes/purge');
        $due->getBody()->write(json_encode($this->payload(1, [
            'purge_after' => '2000-01-01T00:00:00Z',
        ])));
        $dueResponse = $controller(
            $due,
            (new ResponseFactory())->createResponse(),
            ['action' => 'purge']
        );

        self::assertSame(200, $dueResponse->getStatusCode());
        $this->assertLifecycleAck($dueResponse, 3, 1, 'purge', 'purged');
        self::assertNull(json_decode((string) $dueResponse->getBody(), true)['data']['calendar_id']);
        self::assertSame(['mailbox-3-team', 'mailbox-3-unbound'], $baikal->query(
            'SELECT uri FROM calendarinstances WHERE access = 1 ORDER BY uri'
        )->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame(['three', 'two'], $baikal->query(
            'SELECT uid FROM calendarobjects ORDER BY uid'
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(int $version, array $extra = []): array
    {
        return array_merge([
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => 3,
            'principal' => 't1-u2',
            'tenant_prefix' => 't1-',
            'email' => 'user@example.test',
            'name' => 'Test User',
            'lifecycle_version' => $version,
        ], $extra);
    }

    private function lifecycleDatabase(): \Doctrine\DBAL\Connection
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
        $db->executeStatement(<<<'SQL'
CREATE TABLE davyro_mailbox_lifecycle_state (
 tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER, principal VARCHAR(191),
 lifecycle_version INTEGER, last_action VARCHAR(16), updated_at TEXT,
 PRIMARY KEY (tenant_id, user_id, mail_account_id)
)
SQL);

        return $db;
    }

    private function baikalDatabase(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(<<<'SQL'
CREATE TABLE calendars (id INTEGER PRIMARY KEY, synctoken INTEGER, components VARCHAR(32));
CREATE TABLE calendarinstances (
 id INTEGER PRIMARY KEY AUTOINCREMENT, calendarid INTEGER, principaluri VARCHAR(191), access INTEGER,
 displayname TEXT, uri VARCHAR(191), description TEXT
);
CREATE TABLE calendarobjects (
 id INTEGER PRIMARY KEY AUTOINCREMENT, calendarid INTEGER, uri VARCHAR(191), uid VARCHAR(512)
);
CREATE TABLE calendarchanges (id INTEGER PRIMARY KEY AUTOINCREMENT, calendarid INTEGER, uri VARCHAR(191));
CREATE TABLE schedulingobjects (
 principaluri VARCHAR(191), calendardata TEXT, uri VARCHAR(191), UNIQUE (principaluri, uri)
);
INSERT INTO calendars (id, synctoken, components) VALUES
 (1, 1, 'VEVENT'), (2, 1, 'VEVENT'), (3, 1, 'VEVENT');
INSERT INTO calendarinstances (calendarid, principaluri, access, displayname, uri, description) VALUES
 (1, 'principals/t1-u2', 1, 'Primary', 'mailbox-3', ''),
 (2, 'principals/t1-u2', 1, 'Team', 'mailbox-3-team', ''),
 (3, 'principals/t1-u2', 1, 'Unbound collision', 'mailbox-3-unbound', '');
INSERT INTO calendarobjects (calendarid, uri, uid) VALUES
 (1, 'one.ics', 'one'), (2, 'two.ics', 'two'), (3, 'three.ics', 'three');
INSERT INTO calendarchanges (calendarid, uri) VALUES
 (1, 'one.ics'), (2, 'two.ics'), (3, 'three.ics');
SQL);

        return $pdo;
    }

    private function logger(): Logger
    {
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());

        return $logger;
    }

    private function assertLifecycleAck(
        \Psr\Http\Message\ResponseInterface $response,
        int $mailboxId,
        int $version,
        string $action,
        string $status,
    ): void {
        $data = json_decode((string) $response->getBody(), true)['data'];
        self::assertSame($mailboxId, $data['mailbox_id']);
        self::assertSame($version, $data['lifecycle_version']);
        self::assertSame($action, $data['action']);
        self::assertSame($status, $data['status']);
    }

    /** @param array<string, mixed> $services */
    private function container(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            public function __construct(private array $services)
            {
            }
            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException('Unknown service '.$id);
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}
