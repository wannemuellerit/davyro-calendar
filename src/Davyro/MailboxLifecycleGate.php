<?php

declare(strict_types=1);

namespace AgenDAV\Davyro;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

final class MailboxLifecycleGate
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Serializes repair/write operations with mailbox lifecycle changes and
     * rejects bridge state older than an already applied archive or purge.
     *
     * @template T
     * @param array<int, array{id:int,lifecycle_version:int}> $mailboxes
     * @param callable(): T $operation
     * @return T
     */
    public function run(int $tenantId, int $userId, array $mailboxes, callable $operation): mixed
    {
        if ($tenantId < 1 || $userId < 1 || $mailboxes === []) {
            throw new \RuntimeException('Invalid mailbox lifecycle gate context');
        }

        $contexts = [];
        foreach ($mailboxes as $mailbox) {
            $mailAccountId = (int) ($mailbox['id'] ?? 0);
            $lifecycleVersion = (int) ($mailbox['lifecycle_version'] ?? 0);
            if ($mailAccountId < 1 || $lifecycleVersion < 1 || isset($contexts[$mailAccountId])) {
                throw new \RuntimeException('Invalid mailbox lifecycle gate context');
            }
            $contexts[$mailAccountId] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'mail_account_id' => $mailAccountId,
                'principal' => 't'.$tenantId.'-u'.$userId,
                'lifecycle_version' => $lifecycleVersion,
            ];
        }

        return $this->runContexts(array_values($contexts), $operation);
    }

    /**
     * Locks mailbox lifecycle rows from multiple owners in a stable order.
     * A null lifecycle version is used for a shared calendar owner: the
     * current persisted state must be active, but no foreign bridge version
     * is exposed to the share recipient.
     *
     * Missing rows are claimed before the callback. This is essential for the
     * first-open repair path: an archive/purge request and calendar repair then
     * contend on the same persisted row on every supported database.
     *
     * @template T
     * @param array<int, array{
     *   tenant_id:int,
     *   user_id:int,
     *   mail_account_id:int,
     *   principal:string,
     *   lifecycle_version:?int
     * }> $contexts
     * @param callable(): T $operation
     * @return T
     */
    public function runContexts(array $contexts, callable $operation): mixed
    {
        if ($contexts === []) {
            throw new \RuntimeException('Invalid mailbox lifecycle gate context');
        }

        $normalized = [];
        foreach ($contexts as $context) {
            $tenantId = (int) ($context['tenant_id'] ?? 0);
            $userId = (int) ($context['user_id'] ?? 0);
            $mailAccountId = (int) ($context['mail_account_id'] ?? 0);
            $principal = trim((string) ($context['principal'] ?? ''));
            $expectedVersion = $context['lifecycle_version'] ?? null;
            if ($tenantId < 1 || $userId < 1 || $mailAccountId < 1
                || $principal !== 't'.$tenantId.'-u'.$userId
                || ($expectedVersion !== null && (!is_int($expectedVersion) || $expectedVersion < 1))
            ) {
                throw new \RuntimeException('Invalid mailbox lifecycle gate context');
            }
            $key = sprintf('%020d:%020d:%020d', $tenantId, $userId, $mailAccountId);
            if (isset($normalized[$key])) {
                $current = $normalized[$key]['lifecycle_version'];
                if ($current !== null && $expectedVersion !== null && $current !== $expectedVersion) {
                    throw new \RuntimeException('Conflicting mailbox lifecycle versions');
                }
                if ($current === null && $expectedVersion !== null) {
                    $normalized[$key]['lifecycle_version'] = $expectedVersion;
                }
                continue;
            }
            $normalized[$key] = [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'mail_account_id' => $mailAccountId,
                'principal' => $principal,
                'lifecycle_version' => $expectedVersion,
            ];
        }
        ksort($normalized, SORT_STRING);

        return $this->connection->transactional(function () use (
            $normalized,
            $operation,
        ): mixed {
            $suffix = $this->connection->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
            foreach ($normalized as $context) {
                $this->claimMissingState($context);
                $state = $this->connection->fetchAssociative(
                    'SELECT lifecycle_version, last_action FROM davyro_mailbox_lifecycle_state '
                    .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox'.$suffix,
                    [
                        'tenant' => $context['tenant_id'],
                        'user' => $context['user_id'],
                        'mailbox' => $context['mail_account_id'],
                    ]
                );
                if (!is_array($state)) {
                    throw new \RuntimeException('Mailbox lifecycle state could not be claimed');
                }

                $currentVersion = (int) $state['lifecycle_version'];
                $currentAction = (string) $state['last_action'];
                $expectedVersion = $context['lifecycle_version'];
                if ($expectedVersion === null) {
                    if (in_array($currentAction, ['archive', 'purge'], true)) {
                        throw new MailboxLifecycleUnavailable('Mailbox lifecycle context is stale or inactive');
                    }
                    continue;
                }
                if ($currentVersion > $expectedVersion
                    || ($currentVersion === $expectedVersion && in_array($currentAction, ['archive', 'purge'], true))) {
                    throw new MailboxLifecycleUnavailable('Mailbox lifecycle context is stale or inactive');
                }
                if ($currentVersion < $expectedVersion) {
                    $this->connection->update('davyro_mailbox_lifecycle_state', [
                        'principal' => $context['principal'],
                        'lifecycle_version' => $expectedVersion,
                        'last_action' => 'provision',
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ], [
                        'tenant_id' => $context['tenant_id'],
                        'user_id' => $context['user_id'],
                        'mail_account_id' => $context['mail_account_id'],
                    ]);
                }
            }

            return $operation();
        });
    }

    /** @param array{tenant_id:int,user_id:int,mail_account_id:int,principal:string,lifecycle_version:?int} $context */
    private function claimMissingState(array $context): void
    {
        $parameters = [
            'tenant' => $context['tenant_id'],
            'user' => $context['user_id'],
            'mailbox' => $context['mail_account_id'],
            'principal' => $context['principal'],
            'version' => $context['lifecycle_version'] ?? 1,
            'updated' => gmdate('Y-m-d H:i:s'),
        ];
        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $sql = 'INSERT OR IGNORE INTO davyro_mailbox_lifecycle_state '
                .'(tenant_id, user_id, mail_account_id, principal, lifecycle_version, last_action, updated_at) '
                .'VALUES (:tenant, :user, :mailbox, :principal, :version, \'provision\', :updated)';
        } else {
            $sql = 'INSERT INTO davyro_mailbox_lifecycle_state '
                .'(tenant_id, user_id, mail_account_id, principal, lifecycle_version, last_action, updated_at) '
                .'VALUES (:tenant, :user, :mailbox, :principal, :version, \'provision\', :updated) '
                .'ON DUPLICATE KEY UPDATE mail_account_id = mail_account_id';
        }
        $this->connection->executeStatement($sql, $parameters);
    }
}
