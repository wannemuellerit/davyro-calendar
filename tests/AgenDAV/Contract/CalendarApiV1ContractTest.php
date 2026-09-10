<?php

declare(strict_types=1);

namespace AgenDAV\Contract;

use AgenDAV\Davyro\CalendarBridgeClient;
use AgenDAV\Davyro\Import\IcsImportPreview;
use AgenDAV\Davyro\Import\IcsImportResult;
use AgenDAV\Davyro\Import\IcsImportTicket;
use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;

/** Protects the HTTP surface consumed by Davyro Mail's native calendar. */
final class CalendarApiV1ContractTest extends TestCase
{
    public function testRouteSurfaceMatchesTheMailAndBrowserConsumers(): void
    {
        $app = AppFactory::createFromContainer(new Container());
        (require dirname(__DIR__, 3).'/app/routes.php')($app);
        $actual = [];
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $actual[] = $method.' '.$route->getPattern();
            }
        }

        foreach ([
            'POST /api/v1/session',
            'GET /api/v1/context',
            'GET /api/v1/mailboxes/{mailbox_id}/calendars',
            'POST /api/v1/mailboxes/{mailbox_id}/calendars',
            'PATCH /api/v1/calendars/{id}',
            'DELETE /api/v1/calendars/{id}',
            'GET /api/v1/events',
            'POST /api/v1/calendars/{id}/events',
            'PATCH /api/v1/calendars/{id}/events/{uid}',
            'DELETE /api/v1/calendars/{id}/events/{uid}',
            'GET /api/v1/events/{id}/delivery',
            'POST /api/v1/events/{id}/delivery/retry',
            'GET /api/v1/invitations/{id}',
            'POST /api/v1/invitations/{id}/response',
            'GET /api/v1/calendars/{id}/share-candidates',
            'GET /api/v1/calendars/{id}/shares',
            'POST /api/v1/calendars/{id}/shares',
            'DELETE /api/v1/calendars/{id}/shares/{share_id}',
            'POST /api/v1/imports/ics/preview',
            'POST /api/v1/calendars/{id}/imports/ics',
            'GET /api/v1/calendars/{id}/publications',
            'POST /api/v1/calendars/{id}/publications',
            'POST /api/v1/calendars/{id}/publications/rotate',
            'DELETE /api/v1/calendars/{id}/publications/{publication_id}',
            'GET /public/calendars/{token}.ics',
            'GET /api/v1/mailboxes/{mailbox_id}/subscriptions',
            'POST /api/v1/mailboxes/{mailbox_id}/subscriptions',
            'PATCH /api/v1/mailboxes/{mailbox_id}/subscriptions/{id}',
            'DELETE /api/v1/mailboxes/{mailbox_id}/subscriptions/{id}',
            'POST /api/v1/mailboxes/{mailbox_id}/subscriptions/{id}/refresh',
            'GET /api/v1/mailboxes/{id}/availability',
            'PUT /api/v1/mailboxes/{id}/availability',
            'POST /api/v1/availability/check',
            'POST /internal/davyro/invitations/respond',
            'POST /internal/davyro/invitations/reply',
            'POST /internal/davyro/mailboxes/{action}',
            'POST /internal/davyro/calendars/import/preview',
            'POST /internal/davyro/calendars/import/commit',
        ] as $contractRoute) {
            self::assertContains($contractRoute, $actual, 'Missing calendar contract route '.$contractRoute);
        }
    }

    public function testOutboundMailBridgeSignatureCoversExactTargetQueryAndBody(): void
    {
        $secret = str_repeat('bridge-contract-secret-', 2);
        $target = 'internal/calendar/share-candidates?tenant_id=7&user_id=11&query=Max%20Mustermann';
        $body = '{"tenant_id":7,"user_id":11,"mail_account_id":13}';
        $headers = (new \ReflectionMethod(CalendarBridgeClient::class, 'signedHeaders'))->invoke(
            new CalendarBridgeClient('http://portal', $secret),
            'post',
            $target,
            $body,
            'application/json',
        );

        self::assertSame('Bearer '.$secret, $headers['Authorization']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertSame(hash_hmac('sha256', implode("\n", [
            $headers['X-Davyro-Timestamp'],
            $headers['X-Davyro-Nonce'],
            'POST',
            '/'.$target,
            $body,
        ]), $secret), $headers['X-Davyro-Signature']);
    }

    public function testIcsPreviewAndCommitPayloadsMatchBothConsumers(): void
    {
        $preview = new IcsImportPreview('fingerprint', [[
            'uid' => 'event-1',
            'recurrence_id' => null,
            'title' => 'Importierter Termin',
            'start' => '2026-09-15T08:00:00+00:00',
            'end' => '2026-09-15T09:00:00+00:00',
            'all_day' => false,
            'method' => 'REQUEST',
            'status' => 'importable',
            'duplicate' => false,
            'warnings' => [],
        ]], 1, 0, 0);
        $ticket = (new IcsImportTicket(
            str_repeat('a', 64),
            new \DateTimeImmutable('2026-09-10T12:00:00Z'),
            $preview,
        ))->toArray();
        $result = (new IcsImportResult('fingerprint', 1, 0, 0))->toArray();

        self::assertSame([
            'import_token',
            'expires_at',
            'fingerprint',
            'items',
            'summary',
            'sends_rsvp',
        ], array_keys($ticket));
        self::assertSame(['total', 'importable', 'duplicates', 'invalid'], array_keys($ticket['summary']));
        self::assertFalse($ticket['sends_rsvp']);
        self::assertSame(['fingerprint', 'created', 'updated', 'skipped', 'rsvp_sent'], array_keys($result));
        self::assertFalse($result['rsvp_sent']);
    }

    /** @dataProvider producerFieldContract */
    public function testProducerDtosRetainFieldsConsumedByDavyroMail(string $relativeFile, array $fields): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativeFile);
        self::assertIsString($source);
        foreach ($fields as $field) {
            self::assertMatchesRegularExpression(
                "/['\"]".preg_quote($field, '/')."['\"]\\s*=>/",
                $source,
                $relativeFile.' no longer emits '.$field,
            );
        }
    }

    /** @return iterable<string, array{string, string[]}> */
    public static function producerFieldContract(): iterable
    {
        yield 'session' => ['src/Controller/Api/V1/SessionController.php', ['data', 'authenticated', 'csrf_token']];
        yield 'context' => ['src/Controller/Api/V1/ContextController.php', [
            'data', 'user', 'mailboxes', 'selected_mailbox_id', 'calendars', 'preferences', 'csrf_token',
        ]];
        yield 'events' => ['src/Controller/Api/V1/EventsController.php', [
            'data', 'id', 'uid', 'calendar_id', 'title', 'start', 'end', 'all_day', 'timezone', 'etag',
            'organizer_mailbox_id', 'attendees', 'can_edit', 'can_delete', 'can_cancel',
        ]];
        yield 'invitation' => ['src/Controller/Api/V1/InvitationsController.php', [
            'data', 'id', 'mailbox_id', 'event_id', 'sequence', 'method', 'summary', 'status', 'icalendar',
        ]];
        yield 'delivery' => ['src/Controller/Api/V1/EventDeliveryController.php', [
            'data', 'event_id', 'status', 'attempt_count', 'last_error', 'sent_at',
        ]];
        yield 'shares' => ['src/Controller/Api/V1/SharesController.php', [
            'data', 'id', 'user_id', 'name', 'email', 'permission',
        ]];
        yield 'publications' => ['src/Controller/Api/V1/PublicationsController.php', [
            'data', 'id', 'calendar_id', 'https_url', 'webcal_url', 'created_at', 'warning',
        ]];
        yield 'webcal' => ['src/Controller/Api/V1/WebCalSubscriptionsController.php', [
            'data', 'id', 'mailbox_id', 'url_hint', 'name', 'color', 'status', 'last_success_at', 'last_error',
        ]];
        yield 'availability' => ['src/Controller/Api/V1/AvailabilityController.php', [
            'data', 'mailbox_id', 'timezone', 'weekly_windows', 'exceptions', 'available',
        ]];
        yield 'availability check' => ['src/Davyro/Availability/AvailabilityCheckResult.php', [
            'warnings', 'automatically_rejected',
        ]];
    }
}
