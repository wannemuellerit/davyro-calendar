<?php

declare(strict_types=1);

namespace AgenDAV\Controller;

use AgenDAV\Repositories\MailboxCalendarBindingsRepository;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
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
        $this->assertSame('absent', json_decode((string) $newerResult->getBody(), true)['data']['status']);
        $this->assertSame(200, $olderResult->getStatusCode());
        $this->assertSame('stale_ignored', json_decode((string) $olderResult->getBody(), true)['data']['status']);
        $this->assertSame(2, (int) $db->fetchOne('SELECT lifecycle_version FROM davyro_mailbox_lifecycle_state'));
        $this->assertSame('archive', $db->fetchOne('SELECT last_action FROM davyro_mailbox_lifecycle_state'));
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
}
