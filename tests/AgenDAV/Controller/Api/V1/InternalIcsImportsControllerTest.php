<?php

declare(strict_types=1);

namespace AgenDAV\Controller\Api\V1;

use AgenDAV\Davyro\Import\IcsImportCoordinator;
use AgenDAV\Davyro\Import\IcsImportService;
use AgenDAV\Davyro\Import\IcsImportStagingStore;
use AgenDAV\Davyro\Import\IcsImportStore;
use AgenDAV\Davyro\Import\IcsUploadValidator;
use AgenDAV\Davyro\Import\InternalImportTargetResolver;
use AgenDAV\Davyro\Import\StagedIcsImport;
use AgenDAV\Davyro\Import\StoredCalendarObject;
use AgenDAV\Davyro\MailboxLifecycleGate;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class InternalIcsImportsControllerTest extends TestCase
{
    private Connection $db;
    private InternalIcsImportMemoryStore $store;
    private InternalIcsImportStagingStore $staging;
    private InternalIcsImportsController $controller;

    protected function setUp(): void
    {
        $this->db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->db->executeStatement(<<<'SQL'
CREATE TABLE davyro_mailbox_lifecycle_state (
 tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER, principal VARCHAR(191),
 lifecycle_version INTEGER, last_action VARCHAR(16), updated_at TEXT,
 PRIMARY KEY (tenant_id, user_id, mail_account_id)
)
SQL);
        $this->store = new InternalIcsImportMemoryStore();
        $this->staging = new InternalIcsImportStagingStore();
        $services = [
            MailboxLifecycleGate::class => new MailboxLifecycleGate($this->db),
            IcsImportCoordinator::class => new IcsImportCoordinator(
                new IcsImportService($this->store),
                $this->staging,
                new IcsUploadValidator(),
            ),
            InternalImportTargetResolver::class => new class implements InternalImportTargetResolver {
                public function resolve(
                    int $tenantId,
                    int $userId,
                    int $mailAccountId,
                    string $calendarId,
                ): ?string {
                    return $tenantId === 1 && $userId === 2 && $mailAccountId === 3 && $calendarId === 'calendar-one'
                        ? '/dav.php/calendars/t1-u2/mailbox-3/'
                        : null;
                }
            },
        ];
        $container = new class($services) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
        $this->controller = new InternalIcsImportsController($container);
    }

    public function testPreviewRejectsAnArchivedMailboxBeforeStagingOrReadingCalDav(): void
    {
        $this->lifecycle('archive', 2);

        $response = $this->controller->preview(
            $this->jsonRequest('/api/v1/internal/ics-imports/preview', $this->previewPayload(2)),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(0, $this->store->assertWritableCalls);
        self::assertSame([], $this->staging->imports);
    }

    public function testPreviewUsesTheActiveLifecycleVersionAndStagesTheAttachment(): void
    {
        $this->lifecycle('provision', 2);

        $response = $this->controller->preview(
            $this->jsonRequest('/api/v1/internal/ics-imports/preview', $this->previewPayload(2)),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->store->assertWritableCalls);
        self::assertCount(1, $this->staging->imports);
    }

    public function testCommitRejectsIncompleteIdentityAsValidationError(): void
    {
        $response = $this->controller->commit(
            $this->jsonRequest('/api/v1/internal/ics-imports/commit', [
                'tenant_id' => 0,
                'user_id' => 2,
                'mail_account_id' => 3,
                'lifecycle_version' => 2,
                'target_calendar_id' => 'calendar-one',
                'import_token' => 'not-used',
            ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('validation_failed', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    private function lifecycle(string $action, int $version): void
    {
        $this->db->insert('davyro_mailbox_lifecycle_state', [
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => 3,
            'principal' => 't1-u2',
            'lifecycle_version' => $version,
            'last_action' => $action,
            'updated_at' => '2026-09-11 12:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function previewPayload(int $lifecycleVersion): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => 3,
            'lifecycle_version' => $lifecycleVersion,
            'target_calendar_id' => 'calendar-one',
            'source' => [
                'message_id' => '<message@example.test>',
                'attachment_id' => 'part-2',
                'filename' => 'einladung.ics',
            ],
            'mime_type' => 'text/calendar',
            'ics_base64' => base64_encode($this->calendar()),
        ];
    }

    /** @param array<string, mixed> $body */
    private function jsonRequest(string $path, array $body): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write((string) json_encode($body, JSON_THROW_ON_ERROR));

        return $request;
    }

    private function calendar(): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:mail-one\r\n"
            ."DTSTART:20260911T120000Z\r\nDTEND:20260911T130000Z\r\nSUMMARY:Fallback\r\n"
            ."END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}

final class InternalIcsImportMemoryStore implements IcsImportStore
{
    public int $assertWritableCalls = 0;

    public function assertWritable(string $calendarUrl): void
    {
        ++$this->assertWritableCalls;
    }

    public function findByUid(string $calendarUrl, string $uid): ?StoredCalendarObject
    {
        return null;
    }

    public function create(string $calendarUrl, string $uid, string $icalendar): void
    {
    }

    public function replace(StoredCalendarObject $object, string $icalendar): void
    {
    }
}

final class InternalIcsImportStagingStore implements IcsImportStagingStore
{
    /** @var array<string, StagedIcsImport> */
    public array $imports = [];

    public function put(string $token, StagedIcsImport $import, int $ttlSeconds): void
    {
        $this->imports[$token] = $import;
    }

    public function get(string $token): ?StagedIcsImport
    {
        return $this->imports[$token] ?? null;
    }

    public function delete(string $token): void
    {
        unset($this->imports[$token]);
    }
}
