<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use AgenDAV\Controller\InternalInvitationReply;
use AgenDAV\Controller\InternalInvitationResponse;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MailboxLifecycleGateTest extends TestCase
{
    private Connection $db;
    private MailboxLifecycleGate $gate;

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
        $this->gate = new MailboxLifecycleGate($this->db);
    }

    public function testMissingOrOlderActiveStateAllowsCurrentBridgeOperation(): void
    {
        $calls = 0;
        self::assertSame('first repair', $this->gate->run(
            1,
            2,
            [['id' => 3, 'lifecycle_version' => 1]],
            static function () use (&$calls): string {
                ++$calls;

                return 'first repair';
            }
        ));
        self::assertSame([
            'lifecycle_version' => 1,
            'last_action' => 'provision',
        ], $this->db->fetchAssociative(
            'SELECT lifecycle_version, last_action FROM davyro_mailbox_lifecycle_state '
            .'WHERE tenant_id = 1 AND user_id = 2 AND mail_account_id = 3'
        ));

        $this->db->delete('davyro_mailbox_lifecycle_state', [
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => 3,
        ]);
        $this->state(3, 2, 'archive');
        self::assertSame('restored repair', $this->gate->run(
            1,
            2,
            [['id' => 3, 'lifecycle_version' => 3]],
            static function () use (&$calls): string {
                ++$calls;

                return 'restored repair';
            }
        ));
        self::assertSame(2, $calls);
        self::assertSame([
            'lifecycle_version' => 3,
            'last_action' => 'provision',
        ], $this->db->fetchAssociative(
            'SELECT lifecycle_version, last_action FROM davyro_mailbox_lifecycle_state '
            .'WHERE tenant_id = 1 AND user_id = 2 AND mail_account_id = 3'
        ));
    }

    public function testSharedOwnerArchiveIsRejectedWithoutExposingItsVersion(): void
    {
        $this->db->insert('davyro_mailbox_lifecycle_state', [
            'tenant_id' => 1,
            'user_id' => 9,
            'mail_account_id' => 44,
            'principal' => 't1-u9',
            'lifecycle_version' => 7,
            'last_action' => 'archive',
            'updated_at' => '2026-09-11 10:00:00',
        ]);
        $called = false;

        try {
            $this->gate->runContexts([[
                'tenant_id' => 1,
                'user_id' => 9,
                'mail_account_id' => 44,
                'principal' => 't1-u9',
                'lifecycle_version' => null,
            ]], static function () use (&$called): void {
                $called = true;
            });
            self::fail('An archived shared owner must fail closed');
        } catch (MailboxLifecycleUnavailable $exception) {
            self::assertStringContainsString('stale or inactive', $exception->getMessage());
        }
        self::assertFalse($called);
    }

    public function testNewerOrSameVersionArchiveRejectsOperationBeforeCallback(): void
    {
        foreach ([[2, 1], [2, 2]] as [$storedVersion, $bridgeVersion]) {
            $this->db->delete('davyro_mailbox_lifecycle_state', [
                'tenant_id' => 1,
                'user_id' => 2,
                'mail_account_id' => 3,
            ]);
            $this->state(3, $storedVersion, 'archive');
            $called = false;

            try {
                $this->gate->run(
                    1,
                    2,
                    [['id' => 3, 'lifecycle_version' => $bridgeVersion]],
                    static function () use (&$called): void {
                        $called = true;
                    }
                );
                self::fail('Stale bridge state must fail closed');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('stale or inactive', $exception->getMessage());
            }
            self::assertFalse($called);
        }
    }

    public function testConsumedTicketV1CannotEnterRepairAfterArchiveV2(): void
    {
        $this->state(3, 2, 'archive');
        $authenticator = new DavyroSessionAuthenticator($this->container([
            MailboxLifecycleGate::class => $this->gate,
        ]));
        $method = new \ReflectionMethod($authenticator, 'authenticatePayload');
        $payload = [
            'user' => [
                'id' => 2,
                'tenant_id' => 1,
                'name' => 'Test User',
                'principal' => 't1-u2',
                'tenant_principal_prefix' => 't1-',
            ],
            'initial_mail_account_id' => 3,
            'mailboxes' => [[
                'id' => 3,
                'email' => 'user@example.test',
                'name' => 'Mailbox',
                'organizer_aliases' => [],
                'lifecycle_version' => 1,
            ]],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stale or inactive');
        $method->invoke($authenticator, $payload, true);
    }

    public function testInvitationResponseV1CannotProvisionAfterPurgeV2(): void
    {
        $this->state(3, 2, 'purge');
        $controller = new InternalInvitationResponse($this->container([
            MailboxLifecycleGate::class => $this->gate,
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stale or inactive');
        $controller->process($this->invitationPayload());
    }

    public function testInvitationReplyV1FailsBeforeBindingOrBaikalWriteAfterArchiveV2(): void
    {
        $this->state(3, 2, 'archive');
        $logger = new Logger('test');
        $logger->pushHandler(new NullHandler());
        $controller = new InternalInvitationReply($this->container([
            'davyro.bridge_shared_secret' => 'test-secret',
            'monolog' => $logger,
            MailboxLifecycleGate::class => $this->gate,
        ]));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/internal/davyro/invitations/reply')
            ->withHeader('Authorization', 'Bearer test-secret');
        $request->getBody()->write(json_encode($this->replyPayload()));

        $response = $controller($request, (new ResponseFactory())->createResponse());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            'Invitation reply could not be processed',
            json_decode((string) $response->getBody(), true)['message']
        );
    }

    private function state(int $mailAccountId, int $version, string $action): void
    {
        $this->db->insert('davyro_mailbox_lifecycle_state', [
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => $mailAccountId,
            'principal' => 't1-u2',
            'lifecycle_version' => $version,
            'last_action' => $action,
            'updated_at' => '2026-09-11 10:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function invitationPayload(): array
    {
        return [
            'tenant_id' => 1,
            'user_id' => 2,
            'mail_account_id' => 3,
            'lifecycle_version' => 1,
            'principal' => 't1-u2',
            'tenant_prefix' => 't1-',
            'email' => 'user@example.test',
            'name' => 'Test User',
            'status' => 'accepted',
            'icalendar' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
                ."UID:invitation-1\r\nORGANIZER:mailto:organizer@example.test\r\n"
                ."ATTENDEE:mailto:user@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n",
        ];
    }

    /** @return array<string, mixed> */
    private function replyPayload(): array
    {
        $payload = $this->invitationPayload();
        unset($payload['status']);
        $payload['icalendar'] = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REPLY\r\nBEGIN:VEVENT\r\n"
            ."UID:invitation-1\r\nSEQUENCE:1\r\nORGANIZER:mailto:user@example.test\r\n"
            ."ATTENDEE;PARTSTAT=ACCEPTED:mailto:guest@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return $payload;
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
                return $this->services[$id] ?? throw new \RuntimeException('Unexpected service '.$id);
            }
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->services);
            }
        };
    }
}
