<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\CalDAV\Client;
use AgenDAV\CalDAV\Resource\Calendar;
use AgenDAV\Controller\Calendars\Delete as CalendarDelete;
use AgenDAV\Controller\Calendars\Create as CalendarCreate;
use AgenDAV\Controller\Calendars\Save as CalendarSave;
use AgenDAV\Controller\Event\Delete as EventDelete;
use AgenDAV\Controller\Event\Drop;
use AgenDAV\Controller\Event\GetBase;
use AgenDAV\Controller\Event\Listing;
use AgenDAV\Controller\Event\Resize;
use AgenDAV\Controller\Event\Save as EventSave;
use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Data\Share;
use AgenDAV\Data\Subscription;
use AgenDAV\Davyro\BrowserIdCodec;
use AgenDAV\Davyro\CalendarAccess;
use AgenDAV\Davyro\MailboxLifecycleGate;
use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use AgenDAV\Repositories\SharesRepository;
use AgenDAV\Repositories\SubscriptionsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use League\Fractal\Manager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Translation\Translator;

final class LegacyDavyroAuthorizationTest extends TestCase
{
    private Connection $db;
    private MailboxCalendarBindingsRepository $bindings;
    private Session $session;
    private Client&MockObject $client;
    private SharesRepository&MockObject $sharesRepository;
    private SubscriptionsRepository&MockObject $subscriptionsRepository;
    private CalendarAccess $access;
    private ContainerInterface $container;

    /** @var Share[] */
    private array $visibleShares = [];

    /** @var Subscription[] */
    private array $subscriptions = [];

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

        $this->session = new Session(new MockArraySessionStorage());
        $this->session->set('username', 't1-u5');
        $this->session->set('principal_url', '/dav.php/principals/t1-u5/');
        $this->session->set('calendar_home_set', '/dav.php/calendars/t1-u5/');
        $this->session->set('displayname', 'Davyro User');
        $this->session->set('davyro.tenant_id', 1);
        $this->session->set('davyro.tenant_prefix', 't1-');
        $this->session->set('davyro.user_id', 5);
        $this->session->set('davyro.active_mail_account_id', 10);
        $this->session->set('davyro.mailboxes', [
            ['id' => 10, 'email' => 'owner@example.test', 'lifecycle_version' => 1],
            ['id' => 20, 'email' => 'archived@example.test', 'lifecycle_version' => 1],
        ]);

        $this->client = $this->createMock(Client::class);
        $this->sharesRepository = $this->createMock(SharesRepository::class);
        $this->sharesRepository->method('getSharesFor')
            ->willReturnCallback(fn (): array => $this->visibleShares);
        $this->sharesRepository->method('getSharesOnCalendar')->willReturn([]);
        $this->sharesRepository->method('getSourceShare')
            ->willReturnCallback(function (Calendar $calendar): Share {
                foreach ($this->visibleShares as $share) {
                    if ((string) $share->getCalendar() === (string) $calendar->getUrl()) {
                        return $share;
                    }
                }

                throw new \RuntimeException('Missing source share');
            });

        $this->subscriptionsRepository = $this->createMock(SubscriptionsRepository::class);
        $this->subscriptionsRepository->method('getSubscriptionsFor')
            ->willReturnCallback(fn (): array => $this->subscriptions);
        $this->subscriptionsRepository->method('getSubscriptionByUrl')
            ->willReturnCallback(function (Calendar $calendar): Subscription {
                foreach ($this->subscriptions as $subscription) {
                    if ((string) $subscription->getCalendar() === (string) $calendar->getUrl()) {
                        return $subscription;
                    }
                }

                throw new \RuntimeException('Missing subscription');
            });

        $this->access = new CalendarAccess(
            $this->session,
            $this->bindings,
            $this->sharesRepository,
            $this->subscriptionsRepository,
            new BrowserIdCodec(str_repeat('legacy-route-secret-', 3)),
            new MailboxLifecycleGate($this->db),
        );

        $translator = $this->createMock(Translator::class);
        $translator->method('trans')->willReturnCallback(static fn (string $message): string => $message);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('critical')->willReturnCallback(
            static fn (string $message): never => throw new \RuntimeException($message)
        );
        $services = [
            'caldav.client' => $this->client,
            'translator' => $translator,
            'monolog' => $logger,
            'session' => $this->session,
            'calendar.sharing' => false,
            'fractal' => new Manager(),
            'shares.repository' => $this->sharesRepository,
            'subscriptions.repository' => $this->subscriptionsRepository,
            CalendarAccess::class => $this->access,
            MailboxCalendarBindingsRepository::class => $this->bindings,
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

        $this->bindings->ensurePrimary(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10',
            '/dav.php/calendars/t1-u5/mailbox-10/',
            'Owned primary',
        );
    }

    /**
     * @dataProvider eventRouteProvider
     * @param class-string<JSONController> $controllerClass
     * @param array<string, mixed> $input
     */
    public function testEveryLegacyEventRouteRejectsForeignUnboundAndArchivedCalendars(
        string $controllerClass,
        string $method,
        array $input,
    ): void {
        $foreign = $this->bindings->ensurePrimary(
            2,
            5,
            10,
            't2-u5',
            'mailbox-10',
            '/dav.php/calendars/t2-u5/mailbox-10/',
            'Foreign tenant',
        );
        $archived = $this->bindings->ensurePrimary(
            1,
            5,
            20,
            't1-u5',
            'mailbox-20',
            '/dav.php/calendars/t1-u5/mailbox-20/',
            'Archived',
        );
        $this->bindings->archiveMailbox(1, 5, 20, 't1-u5', new \DateTimeImmutable('+30 days'));

        $this->client->expects(self::never())->method('getCalendarByUrl');
        $this->client->expects(self::never())->method('fetchObjectsOnCalendar');
        $this->client->expects(self::never())->method('fetchObjectsOnSubscribedCalendar');
        $this->client->expects(self::never())->method('fetchObjectByUid');

        foreach ([$foreign->calendarUrl(), '/dav.php/calendars/t1-u5/unbound/', $archived->calendarUrl()] as $url) {
            $requestInput = ['calendar' => $url] + $input;
            $request = $method === 'GET'
                ? (new ServerRequestFactory())->createServerRequest('GET', '/legacy')->withQueryParams($requestInput)
                : (new ServerRequestFactory())->createServerRequest('POST', '/legacy')->withParsedBody($requestInput);
            $response = (new $controllerClass($this->container))(
                $request,
                (new ResponseFactory())->createResponse(),
            );

            self::assertSame(404, $response->getStatusCode(), $controllerClass.' accepted '.$url);
        }
    }

    /** @return iterable<string, array{class-string<JSONController>, string, array<string, mixed>}> */
    public static function eventRouteProvider(): iterable
    {
        yield 'listing' => [Listing::class, 'GET', [
            'timezone' => 'UTC',
            'start' => '2026-06-01',
            'end' => '2026-06-30',
            'is_subscribed' => true,
        ]];
        yield 'get base' => [GetBase::class, 'GET', [
            'timezone' => 'UTC',
            'uid' => 'event-1',
        ]];
        yield 'save' => [EventSave::class, 'POST', self::eventSaveInput()];
        yield 'drop' => [Drop::class, 'POST', [
            'timezone' => 'UTC',
            'uid' => 'event-1',
            'delta' => '15',
            'was_allday' => 'false',
            'allday' => 'false',
        ]];
        yield 'resize' => [Resize::class, 'POST', [
            'timezone' => 'UTC',
            'uid' => 'event-1',
            'delta' => '15',
        ]];
        yield 'delete' => [EventDelete::class, 'POST', [
            'uid' => 'event-1',
            'href' => '/event-1.ics',
            'etag' => '"etag"',
        ]];
    }

    public function testEventListingIgnoresForgedSubscriptionFlagForOwnedCalendar(): void
    {
        $primary = $this->bindings->findForMailbox(1, 5, 10)[0];
        $this->client->expects(self::once())->method('fetchObjectsOnCalendar')->willReturn([]);
        $this->client->expects(self::never())->method('fetchObjectsOnSubscribedCalendar');

        $response = (new Listing($this->container))(
            (new ServerRequestFactory())->createServerRequest('GET', '/legacy')->withQueryParams([
                'calendar' => $primary->calendarUrl(),
                'timezone' => 'UTC',
                'start' => '2026-06-01',
                'end' => '2026-06-30',
                'is_subscribed' => true,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    public function testEventListingUsesPersistedSubscriptionWhenFlagIsForgedFalse(): void
    {
        $subscription = $this->subscription('https://feeds.example.test/team.ics');
        $this->client->expects(self::never())->method('fetchObjectsOnCalendar');
        $this->client->expects(self::once())->method('fetchObjectsOnSubscribedCalendar')->willReturn([]);

        $response = (new Listing($this->container))(
            (new ServerRequestFactory())->createServerRequest('GET', '/legacy')->withQueryParams([
                'calendar' => $subscription->getCalendar(),
                'timezone' => 'UTC',
                'start' => '2026-06-01',
                'end' => '2026-06-30',
                'is_subscribed' => false,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @dataProvider legacyWriteRouteProvider
     * @param class-string<JSONController> $controllerClass
     * @param array<string, mixed> $input
     */
    public function testEveryLegacyEventWriteRouteRejectsReadOnlyShare(
        string $controllerClass,
        array $input,
    ): void {
        $shared = $this->sharedBinding(false);
        $this->client->expects(self::never())->method('getCalendarByUrl');
        $this->client->expects(self::never())->method('fetchObjectByUid');

        $response = (new $controllerClass($this->container))(
            (new ServerRequestFactory())->createServerRequest('POST', '/legacy')->withParsedBody(
                ['calendar' => $shared->calendarUrl()] + $input
            ),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    /** @return iterable<string, array{class-string<JSONController>, array<string, mixed>}> */
    public static function legacyWriteRouteProvider(): iterable
    {
        yield 'save' => [EventSave::class, self::eventSaveInput()];
        yield 'drop' => [Drop::class, [
            'timezone' => 'UTC',
            'uid' => 'event-1',
            'delta' => '15',
            'was_allday' => 'false',
            'allday' => 'false',
        ]];
        yield 'resize' => [Resize::class, [
            'timezone' => 'UTC',
            'uid' => 'event-1',
            'delta' => '15',
        ]];
        yield 'delete' => [EventDelete::class, [
            'uid' => 'event-1',
            'href' => '/event-1.ics',
            'etag' => '"etag"',
        ]];
    }

    public function testSharedCalendarCannotEscalateToOwnerAclPathWithForgedFlags(): void
    {
        $shared = $this->sharedBinding(true);
        self::assertSame(CalendarAccess::RESOURCE_SHARED, $this->access->resourceKind($shared->calendarUrl()));
        $this->sharesRepository->expects(self::once())->method('save')->with($this->visibleShares[0]);
        $this->client->expects(self::never())->method('updateCalendar');
        $this->client->expects(self::never())->method('applyACL');

        $response = (new CalendarSave($this->container))(
            $this->post([
                'calendar' => $shared->calendarUrl(),
                'displayname' => 'My shared color',
                'calendar_color' => '#123456',
                'is_owned' => true,
                'is_subscribed' => false,
                'shares' => ['with' => ['/dav.php/principals/t1-u999/'], 'rw' => [true]],
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /** @dataProvider staleCalendarCreateProvider */
    public function testLegacyCalendarCreateCannotWriteAfterArchiveClaim(array $input): void
    {
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
        $this->subscriptionsRepository->expects(self::never())->method('save');
        $before = count($this->bindings->findForMailbox(1, 5, 10));

        $response = (new CalendarCreate($this->container))(
            $this->post($input),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertCount($before, $this->bindings->findForMailbox(1, 5, 10));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function staleCalendarCreateProvider(): iterable
    {
        yield 'additional calendar' => [[
            'displayname' => 'Blocked',
            'calendar_color' => '#123456',
            'is_subscribed' => false,
        ]];
        yield 'WebCal subscription' => [[
            'displayname' => 'Blocked feed',
            'calendar_color' => '#123456',
            'is_subscribed' => true,
            'url' => 'https://example.test/calendar.ics',
        ]];
    }

    public function testSharedCalendarCannotBeDeletedWithForgedOwnerFlag(): void
    {
        $shared = $this->sharedBinding(true);
        $this->client->expects(self::never())->method('deleteCalendar');

        $response = (new CalendarDelete($this->container))(
            $this->post([
                'calendar' => $shared->calendarUrl(),
                'is_owned' => true,
                'is_subscribed' => false,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testOwnedAdditionalCalendarIgnoresForgedSubscriptionAndShareFlagsOnSave(): void
    {
        $additional = $this->additionalBinding();
        $this->client->expects(self::once())
            ->method('updateCalendar')
            ->with(self::callback(static fn (Calendar $calendar): bool => $calendar->getUrl() === $additional->calendarUrl()));
        $this->subscriptionsRepository->expects(self::never())->method('save');
        $this->sharesRepository->expects(self::never())->method('save');

        $response = (new CalendarSave($this->container))(
            $this->post([
                'calendar' => $additional->calendarUrl(),
                'displayname' => 'Renamed',
                'calendar_color' => '#123456',
                'is_owned' => false,
                'is_subscribed' => true,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Renamed', $this->bindings->findActiveByUrl(
            $additional->calendarUrl(),
            1,
            5,
            [10],
        )?->name());
    }

    public function testOwnedAdditionalCalendarIgnoresForgedSubscriptionFlagOnDelete(): void
    {
        $additional = $this->additionalBinding();
        $this->client->expects(self::once())
            ->method('deleteCalendar')
            ->with(self::callback(static fn (Calendar $calendar): bool => $calendar->getUrl() === $additional->calendarUrl()));
        $this->subscriptionsRepository->expects(self::never())->method('remove');

        $response = (new CalendarDelete($this->container))(
            $this->post([
                'calendar' => $additional->calendarUrl(),
                'is_subscribed' => true,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($this->bindings->findActiveByUrl($additional->calendarUrl(), 1, 5, [10]));
    }

    public function testPrimaryCalendarRemainsProtectedWhenSubscriptionFlagIsForged(): void
    {
        $primary = $this->bindings->findForMailbox(1, 5, 10)[0];
        $this->client->expects(self::never())->method('deleteCalendar');

        $response = (new CalendarDelete($this->container))(
            $this->post([
                'calendar' => $primary->calendarUrl(),
                'is_subscribed' => true,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(409, $response->getStatusCode());
    }

    public function testPersistedSubscriptionIgnoresForgedOwnerFlagOnSave(): void
    {
        $subscription = $this->subscription('https://feeds.example.test/team.ics');
        $this->subscriptionsRepository->expects(self::once())->method('save')->with($subscription);
        $this->client->expects(self::never())->method('updateCalendar');

        $response = (new CalendarSave($this->container))(
            $this->post([
                'calendar' => $subscription->getCalendar(),
                'displayname' => 'Team feed',
                'calendar_color' => '#123456',
                'is_owned' => true,
                'is_subscribed' => false,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Team feed', $subscription->getProperty(Calendar::DISPLAYNAME));
    }

    /** @dataProvider calendarControllerProvider */
    public function testCalendarSaveAndDeleteRejectForeignAndArchivedBindings(
        string $controllerClass,
        array $input,
    ): void {
        $foreign = $this->bindings->ensurePrimary(
            2,
            5,
            10,
            't2-u5',
            'mailbox-10',
            '/dav.php/calendars/t2-u5/mailbox-10/',
            'Foreign',
        );
        $archived = $this->bindings->ensurePrimary(
            1,
            5,
            20,
            't1-u5',
            'mailbox-20',
            '/dav.php/calendars/t1-u5/mailbox-20/',
            'Archived',
        );
        $this->bindings->archiveMailbox(1, 5, 20, 't1-u5', new \DateTimeImmutable('+30 days'));
        $this->client->expects(self::never())->method('updateCalendar');
        $this->client->expects(self::never())->method('deleteCalendar');

        foreach ([$foreign->calendarUrl(), $archived->calendarUrl()] as $url) {
            $response = (new $controllerClass($this->container))(
                $this->post(['calendar' => $url] + $input),
                (new ResponseFactory())->createResponse(),
            );
            self::assertSame(404, $response->getStatusCode());
        }
    }

    /** @return iterable<string, array{class-string<JSONController>, array<string, mixed>}> */
    public static function calendarControllerProvider(): iterable
    {
        yield 'save' => [CalendarSave::class, [
            'displayname' => 'Forged',
            'calendar_color' => '#123456',
            'is_owned' => true,
            'is_subscribed' => false,
        ]];
        yield 'delete' => [CalendarDelete::class, [
            'is_owned' => true,
            'is_subscribed' => false,
        ]];
    }

    public function testNonDavyroCalendarSaveKeepsUpstreamBehavior(): void
    {
        $this->session->remove('davyro.tenant_id');
        $this->session->remove('davyro.user_id');
        $this->session->remove('davyro.mailboxes');
        $this->client->expects(self::once())->method('updateCalendar');

        $response = (new CalendarSave($this->container))(
            $this->post([
                'calendar' => '/upstream/calendar/',
                'displayname' => 'Upstream',
                'calendar_color' => '#123456',
                'is_owned' => true,
                'is_subscribed' => false,
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    /** @return array<string, mixed> */
    private static function eventSaveInput(): array
    {
        return [
            'summary' => 'Event',
            'timezone' => 'UTC',
            'start' => '2026-06-01T10:00:00.000000Z',
            'end' => '2026-06-01T11:00:00.000000Z',
            'organizer_email' => 'forged@example.test',
        ];
    }

    private function sharedBinding(bool $writable): MailboxCalendarBinding
    {
        $binding = $this->bindings->ensurePrimary(
            1,
            6,
            77,
            't1-u6',
            'mailbox-77',
            '/dav.php/calendars/t1-u6/mailbox-77/',
            'Shared',
        );
        $share = new Share();
        $share->setOwner('/dav.php/principals/t1-u6/');
        $share->setWith('/dav.php/principals/t1-u5/');
        $share->setCalendar($binding->calendarUrl());
        $share->setWritePermission($writable);
        $this->visibleShares = [$share];

        return $binding;
    }

    private function additionalBinding(): MailboxCalendarBinding
    {
        return $this->bindings->createAdditional(
            1,
            5,
            10,
            't1-u5',
            'mailbox-10-extra',
            '/dav.php/calendars/t1-u5/mailbox-10-extra/',
            'Extra',
            '#6875F5',
        );
    }

    private function subscription(string $url): Subscription
    {
        $subscription = new Subscription();
        $subscription->setOwner('/dav.php/principals/t1-u5/');
        $subscription->setCalendar($url);
        $subscription->setProperty('davyro.mail_account_id', 10);
        $this->subscriptions = [$subscription];

        return $subscription;
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/legacy')
            ->withParsedBody($body);
    }
}
