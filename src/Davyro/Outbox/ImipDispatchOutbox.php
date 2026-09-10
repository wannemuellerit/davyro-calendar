<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Outbox;

use AgenDAV\Uuid;
use Doctrine\DBAL\Connection;

/** Durable hand-off between a committed CalDAV change and Davyro Mail. */
final readonly class ImipDispatchOutbox
{
    public function __construct(
        private Connection $db,
        private ImipTransport $bridge,
    ) {
    }

    /**
     * @param array{tenant_id:int,user_id:int,mail_account_id:int} $context
     * @return array<string, mixed>
     */
    public function queueAndAttempt(string $message, array $context, string $eventUid, string $method): array
    {
        $this->validate($message, $context, $eventUid, $method);
        $id = Uuid::generate();
        $now = gmdate('Y-m-d H:i:s');
        $this->db->insert('davyro_imip_dispatch_outbox', [
            'id' => $id,
            'tenant_id' => $context['tenant_id'],
            'user_id' => $context['user_id'],
            'mail_account_id' => $context['mail_account_id'],
            'event_uid' => $eventUid,
            'method' => strtoupper($method),
            'message' => $message,
            'status' => 'pending',
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'last_error_code' => null,
            'suspended_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'sent_at' => null,
        ]);

        return $this->attempt($id) ?? throw new \RuntimeException('Queued iMIP dispatch disappeared');
    }

    /** @return array<string, mixed>|null */
    public function latest(int $tenantId, int $userId, int $mailAccountId, string $eventUid): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, status, attempt_count, last_error_code, sent_at FROM davyro_imip_dispatch_outbox '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox AND event_uid = :uid '
            ."ORDER BY CASE status WHEN 'processing' THEN 0 WHEN 'pending' THEN 0 WHEN 'failed' THEN 1 ELSE 2 END, created_at DESC, id DESC",
            ['tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId, 'uid' => $eventUid]
        );

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function retryLatest(int $tenantId, int $userId, int $mailAccountId, string $eventUid): ?array
    {
        $latest = $this->latest($tenantId, $userId, $mailAccountId, $eventUid);
        if ($latest === null || in_array($latest['status'], ['sent', 'processing'], true)) {
            return $latest;
        }
        $this->db->update('davyro_imip_dispatch_outbox', [
            'status' => 'pending',
            'next_attempt_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $latest['id']]);

        return $this->attempt((string) $latest['id']);
    }

    /** @return array{processed:int,sent:int,pending:int,failed:int} */
    public function dispatchDue(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid iMIP dispatch batch size');
        }
        $this->db->executeStatement(
            'DELETE FROM davyro_imip_dispatch_outbox WHERE status = :sent AND sent_at < :cutoff',
            ['sent' => 'sent', 'cutoff' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
        );
        $this->db->executeStatement(
            'UPDATE davyro_imip_dispatch_outbox SET status = :pending, next_attempt_at = :now, updated_at = :now '
            .'WHERE status = :processing AND updated_at < :stale',
            [
                'pending' => 'pending',
                'processing' => 'processing',
                'now' => gmdate('Y-m-d H:i:s'),
                'stale' => gmdate('Y-m-d H:i:s', time() - 600),
            ]
        );
        $ids = $this->db->fetchFirstColumn(
            'SELECT id FROM davyro_imip_dispatch_outbox '
            .'WHERE suspended_at IS NULL AND status = :status AND next_attempt_at <= :now '
            .'ORDER BY created_at, id LIMIT '.$limit,
            ['status' => 'pending', 'now' => gmdate('Y-m-d H:i:s')],
            ['status' => \Doctrine\DBAL\ParameterType::STRING],
        );
        $result = ['processed' => 0, 'sent' => 0, 'pending' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            $row = $this->attempt((string) $id);
            if ($row === null) {
                continue;
            }
            ++$result['processed'];
            ++$result[(string) $row['status']];
        }

        return $result;
    }

    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId): void
    {
        $this->db->executeStatement(
            'UPDATE davyro_imip_dispatch_outbox SET suspended_at = :now, updated_at = :now '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox AND sent_at IS NULL',
            ['now' => gmdate('Y-m-d H:i:s'), 'tenant' => $tenantId, 'user' => $userId, 'mailbox' => $mailAccountId]
        );
    }

    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId): void
    {
        $this->db->executeStatement(
            'UPDATE davyro_imip_dispatch_outbox SET suspended_at = NULL, status = :pending, '
            .'next_attempt_at = :now, updated_at = :now '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox AND sent_at IS NULL',
            [
                'pending' => 'pending',
                'now' => gmdate('Y-m-d H:i:s'),
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
            ]
        );
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId): void
    {
        $this->db->delete('davyro_imip_dispatch_outbox', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'mail_account_id' => $mailAccountId,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function attempt(string $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT * FROM davyro_imip_dispatch_outbox WHERE id = :id', ['id' => $id]);
        if ($row === false || $row['suspended_at'] !== null || $row['status'] === 'sent') {
            return $row === false ? null : $row;
        }
        if (!in_array($row['status'], ['pending', 'failed'], true)) {
            return null;
        }
        $claimed = $this->db->executeStatement(
            'UPDATE davyro_imip_dispatch_outbox SET status = :processing, updated_at = :now '
            .'WHERE id = :id AND status = :expected AND suspended_at IS NULL',
            [
                'processing' => 'processing',
                'now' => gmdate('Y-m-d H:i:s'),
                'id' => $id,
                'expected' => $row['status'],
            ]
        );
        if ($claimed !== 1) {
            return null;
        }
        $attempts = (int) $row['attempt_count'] + 1;
        try {
            $this->bridge->sendImipMessage((string) $row['message'], [
                'tenant_id' => (int) $row['tenant_id'],
                'user_id' => (int) $row['user_id'],
                'mail_account_id' => (int) $row['mail_account_id'],
            ]);
            $now = gmdate('Y-m-d H:i:s');
            $this->db->update('davyro_imip_dispatch_outbox', [
                'status' => 'sent',
                'attempt_count' => $attempts,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'updated_at' => $now,
                'sent_at' => $now,
            ], ['id' => $id]);
        } catch (\Throwable) {
            $failed = $attempts >= 10;
            $this->db->update('davyro_imip_dispatch_outbox', [
                'status' => $failed ? 'failed' : 'pending',
                'attempt_count' => $attempts,
                'next_attempt_at' => $failed ? null : gmdate('Y-m-d H:i:s', time() + min(3600, 30 * (2 ** min(7, $attempts)))),
                'last_error_code' => 'mail_bridge_unavailable',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ], ['id' => $id]);
        }

        $updated = $this->db->fetchAssociative(
            'SELECT id, status, attempt_count, last_error_code, sent_at FROM davyro_imip_dispatch_outbox WHERE id = :id',
            ['id' => $id]
        );

        return $updated === false ? null : $updated;
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int} $context */
    private function validate(string $message, array $context, string $eventUid, string $method): void
    {
        if ($message === '' || strlen($message) > 2 * 1024 * 1024
            || $eventUid === '' || strlen($eventUid) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $eventUid) === 1
            || !in_array(strtoupper($method), ['REQUEST', 'REPLY', 'CANCEL'], true)
        ) {
            throw new \InvalidArgumentException('Invalid iMIP dispatch');
        }
        foreach (['tenant_id', 'user_id', 'mail_account_id'] as $field) {
            if (($context[$field] ?? 0) < 1) {
                throw new \InvalidArgumentException('Invalid iMIP dispatch context');
            }
        }
    }
}
