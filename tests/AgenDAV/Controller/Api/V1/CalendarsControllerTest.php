<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Client;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Davyro\BrowserEventReference;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\BrowserIdCodec;
use AgenDAV\Davyro\MailboxLifecycleGate;
use AgenDAV\Davyro\WebCal\WebCalFeedStateRepository;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Preferences;
use AgenDAV\Data\Share;
use AgenDAV\Event\Builder\VObjectBuilder;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\PreferencesRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\Repositories\SubscriptionsRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class CalendarsControllerTest extends TestCase
{
    private MailboxCalendarBindingsRepository $bindings;
    private Connection $db;
    private CalendarAccess $access;
    private BrowserEventReference $eventReferences;
    private Client $client;
    private ContainerInterface $container;
    private SharesRepository $shares;
    /** @var Share[] */
    private array $visibleShares = [];

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement(<<<'SQL'
CREATE TABLE davyro_calendar_bindings (
 id VARCHAR(36) PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER,
 principal VARCHAR(191), calendar_uri VARCHAR(191), calendar_url TEXT, calendar_url_hash VARCHAR(64),
 name VARCHAR(160), color VARCHAR(9), kind VARCHAR(32), is_primary INTEGER, writable INTEGER,
 busy_enabled INTEGER, archived_at TEXT NULL, purge_after TEXT NULL, created_at TEXT, updated_at TEXT,
 UNIQUE (tenant_id, user_id, mail_account_id, principal, calendar_uri)
)
SQL);
        $this->db->executeStatement(<<<'SQL'
CREATE TABLE davyro_mailbox_lifecycle_state (
 tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER, principal VARCHAR(191),
 lifecycle_version INTEGER, last_action VARCHAR(16), updated_at TEXT,
 PRIMARY KEY (tenant_id, user_id, mail_account_id)
)
SQL);
        $this->bindings = new MailboxCalendarBindingsRepository($this->db);
        $session = new Session(new MockArraySessionStorage());
        $session->set('username', 't1-u5');
        $session->set('davyro.tenant_id', 1);
        $session->set('davyro.tenant_prefix', 't1-');
        $session->set('davyro.user_id', 5);
        $session->set('davyro.active_mail_account_id', 10);
        $session->set('davyro.mailboxes', [
            ['id' => 10, 'email' => 'test@example.com', 'lifecycle_version' => 1],
            ['id' => 20, 'email' => 'second@example.com', 'lifecycle_version' => 1],
        ]);
        $session->set('principal_url', '/dav.php/principals/t1-u5/');
        $this->shares = $this->createMock(SharesRepository::class);
        $this->shares->method('getSharesFor')->willReturnCallback(fn (): array => $this->visibleShares);
        $browserIds = new BrowserIdCodec(str_repeat('test-secret-', 4));
        $this->eventReferences = new BrowserEventReference($browserIds);
        $this->client = $this->createMock(Client::class);
        $subscriptions = $this->createMock(SubscriptionsRepository::class);
        $subscriptions->method('getSubscriptionsFor')->willReturn([]);
        $preferences = $this->createMock(PreferencesRepository::class);
        $preferences->method('userPreferences')->willReturn(new Preferences([
            'timezone' => 'Europe/Berlin',
            'weekstart' => 1,
        ]));
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('getToken')->willReturn(new CsrfToken('calendar', 'csrf-token'));
        $this->access = new CalendarAccess(
            $session,
            $this->bindings,
            $this->shares,
            $subscriptions,
            $browserIds,
            new MailboxLifecycleGate($this->db)
        );
        $services = [
            CalendarAccess::class => $this->access,
            BrowserEventReference::class => $this->eventReferences,
            MailboxCalendarBindingsRepository::class => $this->bindings,
            WebCalFeedStateRepository::class => $this->createMock(WebCalFeedStateRepository::class),
            'session' => $session,
            'subscriptions.repository' => $subscriptions,
            'preferences.repository' => $preferences,
            'csrf.manager' => $csrf,
            'csrf.secret' => 'calendar',
            'caldav.client' => $this->client,
            'event.builder' => new VObjectBuilder(new \DateTimeZone('UTC')),
        ];
        $this->container = new class($services) implements ContainerInterface {
            public function __construct(private array $services)
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

    public function testPrimaryCalendarDeletionReturnsConflictWithoutCallingCalDav(): void
    {
        $binding = $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Primary'
        );
        $controller = new CalendarsController($this->container);
        $response = $controller->delete(
            (new ServerRequestFactory())->createServerRequest('DELETE', '/api/v1/calendars/'.$binding->id()),
            (new ResponseFactory())->createResponse(),
            ['id' => $binding->id()]
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('conflict', (string) $response->getBody());
    }

    public function testArchivedMailboxIsRemovedFromContextAndRejectedByMailboxApisForOldSession(): void
    {
        $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Archived mailbox'
        );
        $this->bindings->ensurePrimary(
            1,
            5,
            20,
            't1-u5',
            'mailbox-20',
            '/calendars/t1-u5/mailbox-20/',
            'Active mailbox'
        );
        $archivedPublicId = $this->access->publicMailboxId(10);
        $activePublicId = $this->access->publicMailboxId(20);
        $this->bindings->archiveMailbox(
            1,
            5,
            10,
            't1-u5',
            new \DateTimeImmutable('2099-01-01T00:00:00Z')
        );

        self::assertTrue($this->access->isDavyroSession());
        self::assertSame([20], $this->access->mailboxIds());
        self::assertNull($this->access->mailbox(10));
        self::assertNull($this->access->resolveMailboxId($archivedPublicId));
        self::assertSame(20, $this->access->resolveMailboxId($activePublicId));
        self::assertSame(20, $this->access->selectedMailboxId());

        $context = (new ContextController($this->container))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/context'),
            (new ResponseFactory())->createResponse()
        );
        self::assertSame(200, $context->getStatusCode());
        $contextData = json_decode((string) $context->getBody(), true)['data'];
        self::assertSame([$activePublicId], array_column($contextData['mailboxes'], 'id'));
        self::assertSame($activePublicId, $contextData['selected_mailbox_id']);

        $jsonRequest = static function (string $method, string $path, array $body = []): \Psr\Http\Message\ServerRequestInterface {
            $request = (new ServerRequestFactory())
                ->createServerRequest($method, $path)
                ->withHeader('Content-Type', 'application/json');
            $request->getBody()->write((string) json_encode($body));

            return $request;
        };
        $responses = [];
        $calendars = new CalendarsController($this->container);
        $responses[] = $calendars->list(
            $jsonRequest('GET', '/api/v1/mailboxes/'.$archivedPublicId.'/calendars'),
            (new ResponseFactory())->createResponse(),
            ['mailbox_id' => $archivedPublicId]
        );
        $responses[] = $calendars->create(
            $jsonRequest('POST', '/api/v1/mailboxes/'.$archivedPublicId.'/calendars', ['name' => 'Blocked']),
            (new ResponseFactory())->createResponse(),
            ['mailbox_id' => $archivedPublicId]
        );

        $availability = new AvailabilityController($this->container);
        $responses[] = $availability->get(
            $jsonRequest('GET', '/api/v1/mailboxes/'.$archivedPublicId.'/availability'),
            (new ResponseFactory())->createResponse(),
            ['id' => $archivedPublicId]
        );
        $responses[] = $availability->put(
            $jsonRequest('PUT', '/api/v1/mailboxes/'.$archivedPublicId.'/availability', [
                'timezone' => 'Europe/Berlin',
                'weekly_windows' => [],
                'exceptions' => [],
            ]),
            (new ResponseFactory())->createResponse(),
            ['id' => $archivedPublicId]
        );
        $responses[] = $availability->check(
            $jsonRequest('POST', '/api/v1/availability/check', [
                'mailbox_id' => $archivedPublicId,
                'start' => '2026-09-12T10:00:00Z',
                'end' => '2026-09-12T11:00:00Z',
            ]),
            (new ResponseFactory())->createResponse()
        );

        $subscriptions = new WebCalSubscriptionsController($this->container);
        $responses[] = $subscriptions->list(
            $jsonRequest('GET', '/api/v1/mailboxes/'.$archivedPublicId.'/subscriptions'),
            (new ResponseFactory())->createResponse(),
            ['mailbox_id' => $archivedPublicId]
        );
        $responses[] = $subscriptions->create(
            $jsonRequest('POST', '/api/v1/mailboxes/'.$archivedPublicId.'/subscriptions', [
                'url' => 'https://example.test/calendar.ics',
                'name' => 'Blocked',
            ]),
            (new ResponseFactory())->createResponse(),
            ['mailbox_id' => $archivedPublicId]
        );
        foreach (['update', 'delete', 'refreshOne'] as $method) {
            $responses[] = $subscriptions->{$method}(
                $jsonRequest($method === 'update' ? 'PATCH' : 'POST', '/blocked'),
                (new ResponseFactory())->createResponse(),
                ['mailbox_id' => $archivedPublicId, 'id' => 'blocked-subscription']
            );
        }

        foreach ($responses as $apiResponse) {
            self::assertSame(404, $apiResponse->getStatusCode(), (string) $apiResponse->getBody());
            self::assertSame('not_found', json_decode((string) $apiResponse->getBody(), true)['error']['code']);
        }

        $this->bindings->archiveMailbox(
            1,
            5,
            20,
            't1-u5',
            new \DateTimeImmutable('2099-01-01T00:00:00Z')
        );
        self::assertTrue($this->access->isDavyroSession());
        self::assertSame([], $this->access->mailboxIds());
        $staleContext = (new ContextController($this->container))(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/context'),
            (new ResponseFactory())->createResponse()
        );
        self::assertSame(410, $staleContext->getStatusCode());
        self::assertSame('session_stale', json_decode((string) $staleContext->getBody(), true)['error']['code']);
    }

    public function testLifecycleGateRejectsMutationWhenArchiveStateWinsBeforeBindingIsArchived(): void
    {
        $this->ensureActiveSessionMailbox();
        $publicId = $this->access->publicMailboxId(10);
        $this->db->insert('davyro_mailbox_lifecycle_state', [
            'tenant_id' => 1,
            'user_id' => 5,
            'mail_account_id' => 10,
            'principal' => 't1-u5',
            'lifecycle_version' => 1,
            'last_action' => 'archive',
            'updated_at' => '2026-09-11 10:00:00',
        ]);
        $this->client->expects(self::never())->method('createCalendar');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/mailboxes/'.$publicId.'/calendars')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{"name":"Blocked by lifecycle"}');

        $response = (new CalendarsController($this->container))->create(
            $request,
            (new ResponseFactory())->createResponse(),
            ['mailbox_id' => $publicId]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertCount(1, $this->bindings->findForMailbox(1, 5, 10));
    }

    public function testUnknownAndCrossTenantOpaqueIdsReturnNotFound(): void
    {
        $foreign = $this->bindings->ensurePrimary(
            2,
            5,
            10,
            't2-u5',
            'mailbox-10',
            '/calendars/t2-u5/mailbox-10/',
            'Foreign'
        );
        $controller = new CalendarsController($this->container);
        $response = $controller->delete(
            (new ServerRequestFactory())->createServerRequest('DELETE', '/api/v1/calendars/'.$foreign->id()),
            (new ResponseFactory())->createResponse(),
            ['id' => $foreign->id()]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('Foreign', (string) $response->getBody());
    }

    public function testCalendarMutationStopsAfterArchiveClaimBeforeCallingCalDav(): void
    {
        $binding = $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Primary'
        );
        $this->db->insert('davyro_mailbox_lifecycle_state', [
            'tenant_id' => 1,
            'user_id' => 5,
            'mail_account_id' => 10,
            'principal' => 't1-u5',
            'lifecycle_version' => 1,
            'last_action' => 'archive',
            'updated_at' => '2026-09-11 10:00:00',
        ]);
        $this->client->expects(self::never())->method('getCalendarByUrl');
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/calendars/'.$binding->id())
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{"name":"Must not be written"}');

        $response = (new CalendarsController($this->container))->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => $binding->id()]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('not_found', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function testEventUpdateRequiresIfMatch(): void
    {
        $binding = $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Primary'
        );
        $controller = new EventsController($this->container);
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/calendars/'.$binding->id().'/events/event-1')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write('{"title":"Changed","scope":"series"}');

        $response = $controller->update(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => $binding->id(), 'uid' => 'event-1']
        );

        $this->assertSame(428, $response->getStatusCode());
        $this->assertStringContainsString('precondition_required', (string) $response->getBody());
    }

    public function testCombinedEventViewFetchesOnlyAuthorizedOwnedAndSameTenantSharedBindings(): void
    {
        $owned = $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Owned'
        );
        $unavailableMailbox = $this->bindings->ensurePrimary(
            1,
            5,
            99,
            't1-u5',
            'mailbox-99',
            '/calendars/t1-u5/mailbox-99/',
            'Unavailable mailbox'
        );
        $shared = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Shared'
        );
        $foreign = $this->bindings->ensurePrimary(
            2,
            5,
            10,
            't2-u5',
            'mailbox-10',
            '/calendars/t2-u5/mailbox-10/',
            'Foreign tenant'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($shared->calendarUrl());
        $share->setWritePermission(false);
        $this->visibleShares = [$share];

        $fetched = [];
        $this->client->expects(self::exactly(2))
            ->method('fetchObjectsOnCalendar')
            ->willReturnCallback(static function (Calendar $calendar) use (&$fetched): array {
                $fetched[] = $calendar->getUrl();

                return [];
            });
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/events')
            ->withQueryParams([
                'from' => '2026-09-01T00:00:00Z',
                'to' => '2026-10-01T00:00:00Z',
            ]);

        $response = (new EventsController($this->container))->list(
            $request,
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getBody(), true)['data']);
        sort($fetched);
        $expected = [$owned->calendarUrl(), $shared->calendarUrl()];
        sort($expected);
        self::assertSame($expected, $fetched);
        self::assertNotContains($unavailableMailbox->calendarUrl(), $fetched);
        self::assertNotContains($foreign->calendarUrl(), $fetched);
    }

    public function testExplicitForeignCalendarInEventListingReturnsNeutralNotFoundBeforeCalDav(): void
    {
        $foreign = $this->bindings->ensurePrimary(
            2,
            5,
            10,
            't2-u5',
            'mailbox-10',
            '/calendars/t2-u5/mailbox-10/',
            'Foreign'
        );
        $this->client->expects(self::never())->method('fetchObjectsOnCalendar');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/events')
            ->withQueryParams([
                'from' => '2026-09-01T00:00:00Z',
                'to' => '2026-10-01T00:00:00Z',
                'calendar_ids' => [$foreign->id()],
            ]);

        $response = (new EventsController($this->container))->list(
            $request,
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Foreign', (string) $response->getBody());
    }

    public function testForeignCalendarCannotBeCreatedUpdatedOrDeletedThroughEventApi(): void
    {
        $foreign = $this->bindings->ensurePrimary(
            2,
            5,
            10,
            't2-u5',
            'mailbox-10',
            '/calendars/t2-u5/mailbox-10/',
            'Foreign'
        );
        $this->client->expects(self::never())->method('getCalendarByUrl');
        $controller = new EventsController($this->container);
        $create = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/calendars/'.$foreign->id().'/events')
            ->withHeader('Content-Type', 'application/json');
        $create->getBody()->write(json_encode([
            'title' => 'Forbidden',
            'start' => '2026-09-10T10:00:00Z',
            'end' => '2026-09-10T11:00:00Z',
            'timezone' => 'UTC',
        ]));
        $update = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/v1/calendars/'.$foreign->id().'/events/opaque-event')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('If-Match', '"etag"');
        $update->getBody()->write('{"title":"Forbidden"}');
        $delete = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/v1/calendars/'.$foreign->id().'/events/opaque-event')
            ->withHeader('If-Match', '"etag"');

        $responses = [
            $controller->create($create, (new ResponseFactory())->createResponse(), ['id' => $foreign->id()]),
            $controller->update($update, (new ResponseFactory())->createResponse(), [
                'id' => $foreign->id(),
                'uid' => 'opaque-event',
            ]),
            $controller->delete($delete, (new ResponseFactory())->createResponse(), [
                'id' => $foreign->id(),
                'uid' => 'opaque-event',
            ]),
        ];

        foreach ($responses as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertStringNotContainsString('Foreign', (string) $response->getBody());
        }
    }

    public function testReadOnlySharedCalendarRejectsCreateBeforeCalDav(): void
    {
        $this->ensureActiveSessionMailbox();
        $shared = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Read only'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($shared->calendarUrl());
        $share->setWritePermission(false);
        $this->visibleShares = [$share];
        $this->client->expects(self::never())->method('getCalendarByUrl');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/calendars/'.$shared->id().'/events')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode([
            'title' => 'Forbidden',
            'start' => '2026-09-10T10:00:00Z',
            'end' => '2026-09-10T11:00:00Z',
            'timezone' => 'UTC',
        ]));

        $response = (new EventsController($this->container))->create(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => $shared->id()]
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testOwnedCalendarDeterminesOrganizerMailboxAndRejectsASelectionFromAnotherMailbox(): void
    {
        $owned = $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Owned'
        );
        $this->bindings->ensurePrimary(
            1,
            5,
            20,
            't1-u5',
            'mailbox-20',
            '/calendars/t1-u5/mailbox-20/',
            'Other mailbox'
        );
        $this->client->expects(self::never())->method('getCalendarByUrl');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/calendars/'.$owned->id().'/events')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode([
            'title' => 'Wrong organizer mailbox',
            'start' => '2026-09-10T10:00:00Z',
            'end' => '2026-09-10T11:00:00Z',
            'timezone' => 'UTC',
            'organizer_mailbox_id' => $this->access->publicMailboxId(20),
        ]));

        $response = (new EventsController($this->container))->create(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => $owned->id()]
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('selected calendar determines', (string) $response->getBody());
    }

    public function testWritableSharedCalendarRejectsAnOrganizerOtherThanTheActiveMailbox(): void
    {
        $this->ensureActiveSessionMailbox();
        $this->bindings->ensurePrimary(
            1,
            5,
            20,
            't1-u5',
            'mailbox-20',
            '/calendars/t1-u5/mailbox-20/',
            'Other mailbox'
        );
        $shared = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Team'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($shared->calendarUrl());
        $share->setWritePermission(true);
        $this->visibleShares = [$share];
        $this->client->expects(self::never())->method('getCalendarByUrl');
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/v1/calendars/'.$shared->id().'/events')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode([
            'title' => 'Missing organizer mailbox',
            'start' => '2026-09-10T10:00:00Z',
            'end' => '2026-09-10T11:00:00Z',
            'timezone' => 'UTC',
            'organizer_mailbox_id' => $this->access->publicMailboxId(20),
        ]));

        $response = (new EventsController($this->container))->create(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => $shared->id()]
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('active mailbox determines', (string) $response->getBody());
    }

    public function testSameTenantSharedCalendarUsesOpaqueBindingAndReadOnlyAccess(): void
    {
        $this->ensureActiveSessionMailbox();
        $binding = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Shared calendar'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($binding->calendarUrl());
        $share->setWritePermission(false);
        $this->visibleShares = [$share];

        $visible = $this->access->bindingById($binding->id());

        $this->assertNotNull($visible);
        $this->assertSame(MailboxCalendarBinding::KIND_SHARED, $visible->kind());
        $this->assertFalse($visible->isWritable());
        $this->assertNull($visible->toApiArray()['mailbox_id']);
        $this->assertTrue($this->access->canRead($binding->calendarUrl()));
        $this->assertFalse($this->access->canWrite($binding->calendarUrl()));
    }

    public function testCrossTenantShareIsInvisible(): void
    {
        $this->ensureActiveSessionMailbox();
        $binding = $this->bindings->ensurePrimary(
            2,
            6,
            11,
            't2-u6',
            'mailbox-11',
            '/calendars/t2-u6/mailbox-11/',
            'Foreign shared calendar'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t2-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($binding->calendarUrl());
        $share->setWritePermission(true);
        $this->visibleShares = [$share];

        $this->assertNull($this->access->bindingById($binding->id()));
        $this->assertFalse($this->access->canRead($binding->calendarUrl()));
        $this->assertFalse($this->access->canWrite($binding->calendarUrl()));
    }

    public function testArchivingOwnerMailboxImmediatelyHidesAndRestoreReenablesSharedCalendar(): void
    {
        $this->ensureActiveSessionMailbox();
        $binding = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Shared calendar'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($binding->calendarUrl());
        $share->setWritePermission(true);
        $this->visibleShares = [$share];

        self::assertNotNull($this->access->bindingById($binding->id()));
        self::assertTrue($this->access->canRead($binding->calendarUrl()));
        self::assertTrue($this->access->canWrite($binding->calendarUrl()));

        $this->bindings->archiveMailbox(
            1,
            6,
            11,
            't1-u6',
            new \DateTimeImmutable('2099-01-01T00:00:00Z')
        );

        self::assertNull($this->access->bindingById($binding->id()));
        self::assertFalse($this->access->canRead($binding->calendarUrl()));
        self::assertFalse($this->access->canWrite($binding->calendarUrl()));

        $this->bindings->restoreMailbox(1, 6, 11, 't1-u6');

        self::assertNotNull($this->access->bindingById($binding->id()));
        self::assertTrue($this->access->canRead($binding->calendarUrl()));
        self::assertTrue($this->access->canWrite($binding->calendarUrl()));
    }

    public function testNonOrganizerCannotDeleteAnEventFromWritableSharedCalendar(): void
    {
        $this->ensureActiveSessionMailbox();
        $binding = $this->bindings->ensurePrimary(
            1,
            6,
            11,
            't1-u6',
            'mailbox-11',
            '/calendars/t1-u6/mailbox-11/',
            'Shared calendar'
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($binding->calendarUrl());
        $share->setWritePermission(true);
        $this->visibleShares = [$share];

        $builder = new VObjectBuilder(new \DateTimeZone('UTC'));
        $event = $builder->createEvent('foreign-event');
        $instance = $event->createEventInstance();
        $instance->setSummary('Foreign invitation');
        $instance->setStart(new \DateTimeImmutable('2026-09-10T10:00:00Z'));
        $instance->setEnd(new \DateTimeImmutable('2026-09-10T11:00:00Z'));
        $instance->setOrganizer('external@example.net', 'External');
        $event->storeInstance($instance);
        $object = (new CalendarObject($binding->calendarUrl().'foreign-event.ics', $event))->setEtag('"etag"');
        $this->client->expects($this->once())->method('getCalendarByUrl')
            ->with($binding->calendarUrl())->willReturn(new Calendar($binding->calendarUrl()));
        $this->client->expects($this->once())->method('fetchObjectByUid')->willReturn($object);
        $this->client->expects($this->never())->method('deleteCalendarObject');

        $token = $this->eventReferences->event(1, 5, 10, $binding->id(), 'foreign-event');
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/api/v1/calendars/'.$binding->id().'/events/'.$token.'?scope=series')
            ->withHeader('If-Match', '"etag"');
        $controller = new EventsController($this->container);
        $dtoMethod = new \ReflectionMethod($controller, 'bindingEventDto');
        $dto = $dtoMethod->invoke($controller, $this->access->bindingById($binding->id()), $object, $instance);
        $this->assertFalse($dto['can_edit']);
        $this->assertFalse($dto['can_delete']);
        $this->assertFalse($dto['can_cancel']);

        $response = $controller->delete(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => $binding->id(), 'uid' => $token]
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('Only the organizer', (string) $response->getBody());
    }

    public function testIsoInstantIsNormalizedToTheEventTimezoneAcrossDst(): void
    {
        $controller = new EventsController($this->container);
        $method = new \ReflectionMethod($controller, 'date');
        $date = $method->invoke(
            $controller,
            '2026-03-29T01:30:00Z',
            new \DateTimeZone('Europe/Berlin'),
            'start'
        );

        $this->assertSame('Europe/Berlin', $date->getTimezone()->getName());
        $this->assertSame('2026-03-29T03:30:00+02:00', $date->format(DATE_ATOM));
    }

    private function ensureActiveSessionMailbox(): MailboxCalendarBinding
    {
        return $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/calendars/t1-u5/mailbox-10/',
            'Active mailbox'
        );
    }
}
