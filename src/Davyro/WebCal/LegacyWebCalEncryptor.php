<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

use AgenDAV\Data\WebCalFeedState;
use AgenDAV\Davyro\SubscriptionFeedFetcher;
use AgenDAV\Uuid;
use Doctrine\DBAL\Connection;

/** One-way upgrade path for WebCal rows created before URL encryption. */
final readonly class LegacyWebCalEncryptor
{
    private const ID_PROPERTY = 'davyro.subscription_id';
    private const MAILBOX_PROPERTY = 'davyro.mail_account_id';

    public function __construct(
        private Connection $connection,
        private SubscriptionFeedFetcher $fetcher,
        private WebCalUrlCipher $cipher,
    ) {
    }

    public function migrate(): LegacyWebCalEncryptionResult
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT sid, calendar, options FROM subscriptions WHERE calendar LIKE 'http://%' "
            ."OR calendar LIKE 'https://%' OR calendar LIKE 'webcal://%'"
        );
        $migrated = 0;
        $unresolved = 0;
        foreach ($rows as $row) {
            try {
                $this->migrateRow($row);
                ++$migrated;
            } catch (\Throwable) {
                // Do not log or return the URL. Startup fails closed until the
                // orphaned subscription is repaired or removed explicitly.
                ++$unresolved;
            }
        }

        return new LegacyWebCalEncryptionResult($migrated, $unresolved);
    }

    /** @param array<string, mixed> $row */
    private function migrateRow(array $row): void
    {
        $options = json_decode((string) ($row['options'] ?? ''), true);
        $options = is_array($options) ? $options : [];
        $mailAccountId = (int) ($options[self::MAILBOX_PROPERTY] ?? 0);
        if ($mailAccountId < 1) {
            throw new \RuntimeException('Legacy WebCal subscription has no mailbox');
        }
        $owners = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT tenant_id, user_id FROM davyro_calendar_bindings '
            .'WHERE mail_account_id = :mailbox',
            ['mailbox' => $mailAccountId],
        );
        if (count($owners) !== 1) {
            throw new \RuntimeException('Legacy WebCal mailbox owner is ambiguous');
        }
        $tenantId = (int) $owners[0]['tenant_id'];
        $userId = (int) $owners[0]['user_id'];
        $url = $this->fetcher->normalizeUrl((string) ($row['calendar'] ?? ''));
        $subscriptionId = trim((string) ($options[self::ID_PROPERTY] ?? ''));
        try {
            WebCalReference::create($subscriptionId);
        } catch (\InvalidArgumentException) {
            $subscriptionId = Uuid::generate();
        }
        $options[self::ID_PROPERTY] = $subscriptionId;
        $options[self::MAILBOX_PROPERTY] = $mailAccountId;
        $encrypted = $this->cipher->encrypt($url);
        $hint = (string) parse_url($url, PHP_URL_HOST);
        if ($hint === '') {
            throw new \RuntimeException('Legacy WebCal URL has no host');
        }

        $this->connection->transactional(function () use (
            $tenantId,
            $userId,
            $mailAccountId,
            $subscriptionId,
            $encrypted,
            $hint,
            $row,
            $options,
        ): void {
            $stateId = $this->connection->fetchOne(
                'SELECT id FROM davyro_webcal_feed_states '
                .'WHERE tenant_id = :tenant AND user_id = :user AND subscription_id = :subscription',
                ['tenant' => $tenantId, 'user' => $userId, 'subscription' => $subscriptionId],
            );
            if (is_string($stateId) && $stateId !== '') {
                $this->connection->update('davyro_webcal_feed_states', [
                    'encrypted_url' => $encrypted,
                    'url_hint' => mb_substr(strtolower(rtrim($hint, '.')), 0, 255),
                ], ['id' => $stateId]);
            } else {
                $this->connection->insert('davyro_webcal_feed_states', [
                    'id' => Uuid::generate(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'mail_account_id' => $mailAccountId,
                    'subscription_id' => $subscriptionId,
                    'encrypted_url' => $encrypted,
                    'url_hint' => mb_substr(strtolower(rtrim($hint, '.')), 0, 255),
                    'status' => WebCalFeedState::STATUS_ERROR,
                    'etag' => null,
                    'last_modified' => null,
                    'cached_body' => null,
                    'last_attempt_at' => null,
                    'last_success_at' => null,
                    'next_refresh_at' => null,
                    'stale_until' => null,
                    'last_error' => null,
                    'suspended_at' => null,
                ]);
            }
            $this->connection->update('subscriptions', [
                'calendar' => WebCalReference::create($subscriptionId),
                'options' => json_encode($options, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ], ['sid' => (int) $row['sid']]);
        });
    }
}
