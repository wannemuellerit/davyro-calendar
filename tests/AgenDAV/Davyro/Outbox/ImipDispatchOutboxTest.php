<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Outbox;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ImipDispatchOutboxTest extends TestCase
{
    public function testBridgeFailureRemainsDurableAndManualRetryDeliversTheSameMessage(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement(<<<'SQL'
CREATE TABLE davyro_imip_dispatch_outbox (
 id VARCHAR(36) PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER,
 event_uid VARCHAR(512), method VARCHAR(16), message TEXT, status VARCHAR(16), attempt_count INTEGER,
 next_attempt_at TEXT NULL, last_error_code VARCHAR(64) NULL, suspended_at TEXT NULL,
 created_at TEXT, updated_at TEXT, sent_at TEXT NULL
)
SQL);
        $attempt = 0;
        $transport = $this->createMock(ImipTransport::class);
        $transport->expects($this->exactly(2))->method('sendImipMessage')
            ->willReturnCallback(static function () use (&$attempt): void {
                ++$attempt;
                if ($attempt === 1) {
                    throw new \RuntimeException('Bridge is down');
                }
            });
        $outbox = new ImipDispatchOutbox($db, $transport);
        $context = ['tenant_id' => 1, 'user_id' => 2, 'mail_account_id' => 3];

        $queued = $outbox->queueAndAttempt('valid MIME message', $context, 'event-uid', 'REQUEST');
        $this->assertSame('pending', $queued['status']);
        $this->assertSame(1, (int) $queued['attempt_count']);
        $this->assertSame('valid MIME message', $db->fetchOne('SELECT message FROM davyro_imip_dispatch_outbox'));

        $retried = $outbox->retryLatest(1, 2, 3, 'event-uid');
        $this->assertSame('sent', $retried['status']);
        $this->assertSame(2, (int) $retried['attempt_count']);
        $this->assertNotNull($retried['sent_at']);
    }

    public function testArchivingSuspendsAndPurgingRemovesPendingMessages(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement(<<<'SQL'
CREATE TABLE davyro_imip_dispatch_outbox (
 id VARCHAR(36) PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, mail_account_id INTEGER,
 event_uid VARCHAR(512), method VARCHAR(16), message TEXT, status VARCHAR(16), attempt_count INTEGER,
 next_attempt_at TEXT NULL, last_error_code VARCHAR(64) NULL, suspended_at TEXT NULL,
 created_at TEXT, updated_at TEXT, sent_at TEXT NULL
)
SQL);
        $transport = $this->createMock(ImipTransport::class);
        $transport->method('sendImipMessage')->willThrowException(new \RuntimeException('Bridge is down'));
        $outbox = new ImipDispatchOutbox($db, $transport);
        $outbox->queueAndAttempt(
            'valid MIME message',
            ['tenant_id' => 1, 'user_id' => 2, 'mail_account_id' => 3],
            'event-uid',
            'CANCEL'
        );

        $outbox->archiveMailbox(1, 2, 3);
        $this->assertNotNull($db->fetchOne('SELECT suspended_at FROM davyro_imip_dispatch_outbox'));
        $outbox->restoreMailbox(1, 2, 3);
        $this->assertNull($db->fetchOne('SELECT suspended_at FROM davyro_imip_dispatch_outbox'));
        $outbox->purgeMailbox(1, 2, 3);
        $this->assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM davyro_imip_dispatch_outbox'));
    }
}
