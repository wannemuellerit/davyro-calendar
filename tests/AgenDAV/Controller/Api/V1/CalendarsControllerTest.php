<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\CalDAV\Client;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\CalDAV\Resource\CalendarObject;
use AgenDAV\Davyro\BrowserEventReference;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\BrowserIdCodec;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Share;
use AgenDAV\Event\Builder\VObjectBuilder;
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

final class CalendarsControllerTest extends TestCase
{
    private MailboxCalendarBindingsRepository $bindings;
    private CalendarAccess $access;
    private BrowserEventReference $eventReferences;
    private Client $client;
    private ContainerInterface $container;
    private SharesRepository $shares;
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
        $session->set('davyro.mailboxes', [['id' => 10, 'email' => 'test@example.com']]);
        $session->set('principal_url', '/dav.php/principals/t1-u5/');
        $this->shares = $this->createMock(SharesRepository::class);
        $this->shares->method('getSharesFor')->willReturnCallback(fn (): array => $this->visibleShares);
        $browserIds = new BrowserIdCodec(str_repeat('test-secret-', 4));
        $this->eventReferences = new BrowserEventReference($browserIds);
        $this->client = $this->createMock(Client::class);
        $this->access = new CalendarAccess(
            $session,
            $this->bindings,
            $this->shares,
            $this->createMock(SubscriptionsRepository::class),
            $browserIds
        );
        $services = [
            CalendarAccess::class => $this->access,
            BrowserEventReference::class => $this->eventReferences,
            MailboxCalendarBindingsRepository::class => $this->bindings,
            'caldav.client' => $this->client,
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

    public function testSameTenantSharedCalendarUsesOpaqueBindingAndReadOnlyAccess(): void
    {
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

    public function testNonOrganizerCannotDeleteAnEventFromWritableSharedCalendar(): void
    {
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
}
