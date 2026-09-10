<?php

declare(strict_types=1);

namespace AgenDAV\Repositories;

use AgenDAV\Data\MailboxCalendarBinding;
use AgenDAV\Uuid;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class MailboxCalendarBindingsRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function ensurePrimary(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $principal,
        string $calendarUri,
        string $calendarUrl,
        string $name,
        string $color = '#6875F5',
    ): MailboxCalendarBinding {
        $existing = $this->findExact($tenantId, $userId, $mailAccountId, $principal, $calendarUri, true);
        $now = self::now();

        if ($existing === null) {
            $this->connection->insert('davyro_calendar_bindings', [
                'id' => Uuid::generate(),
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'mail_account_id' => $mailAccountId,
                'principal' => $principal,
                'calendar_uri' => $calendarUri,
                'calendar_url' => self::canonicalUrl($calendarUrl),
                'calendar_url_hash' => self::urlHash($calendarUrl),
                'name' => $name,
                'color' => self::normalizeColor($color),
                'kind' => MailboxCalendarBinding::KIND_PRIMARY,
                'is_primary' => 1,
                'writable' => 1,
                'busy_enabled' => 1,
                'archived_at' => null,
                'purge_after' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $this->connection->update('davyro_calendar_bindings', [
                'calendar_url' => self::canonicalUrl($calendarUrl),
                'calendar_url_hash' => self::urlHash($calendarUrl),
                'name' => $name,
                'kind' => MailboxCalendarBinding::KIND_PRIMARY,
                'is_primary' => 1,
                'writable' => 1,
                'archived_at' => null,
                'purge_after' => null,
                'updated_at' => $now,
            ], ['id' => $existing->id()]);
        }

        return $this->findExact($tenantId, $userId, $mailAccountId, $principal, $calendarUri, true)
            ?? throw new \RuntimeException('Primary calendar binding could not be persisted');
    }

    public function createAdditional(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $principal,
        string $calendarUri,
        string $calendarUrl,
        string $name,
        string $color,
        bool $busyEnabled = true,
    ): MailboxCalendarBinding {
        $existing = $this->findExact($tenantId, $userId, $mailAccountId, $principal, $calendarUri, true);
        if ($existing !== null) {
            if ($existing->isPrimary()) {
                throw new \DomainException('A primary calendar cannot be replaced');
            }

            return $existing;
        }

        $now = self::now();
        $id = Uuid::generate();
        $this->connection->insert('davyro_calendar_bindings', [
            'id' => $id,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'mail_account_id' => $mailAccountId,
            'principal' => $principal,
            'calendar_uri' => $calendarUri,
            'calendar_url' => self::canonicalUrl($calendarUrl),
            'calendar_url_hash' => self::urlHash($calendarUrl),
            'name' => $name,
            'color' => self::normalizeColor($color),
            'kind' => MailboxCalendarBinding::KIND_ADDITIONAL,
            'is_primary' => 0,
            'writable' => 1,
            'busy_enabled' => $busyEnabled ? 1 : 0,
            'archived_at' => null,
            'purge_after' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByIdUnscoped($id)
            ?? throw new \RuntimeException('Calendar binding could not be persisted');
    }

    /** @return MailboxCalendarBinding[] */
    public function findActiveForUser(int $tenantId, int $userId, array $mailAccountIds = []): array
    {
        $params = ['tenant' => $tenantId, 'user' => $userId];
        $types = [];
        $sql = 'SELECT * FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND user_id = :user AND archived_at IS NULL';
        if ($mailAccountIds !== []) {
            $mailAccountIds = array_values(array_unique(array_map('intval', $mailAccountIds)));
            $sql .= ' AND mail_account_id IN (:mailboxes)';
            $params['mailboxes'] = $mailAccountIds;
            $types['mailboxes'] = ArrayParameterType::INTEGER;
        }
        $sql .= ' ORDER BY mail_account_id, is_primary DESC, name, id';

        return array_map([$this, 'hydrate'], $this->connection->fetchAllAssociative($sql, $params, $types));
    }

    /** @return MailboxCalendarBinding[] */
    public function findForMailbox(int $tenantId, int $userId, int $mailAccountId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY is_primary DESC, name, id';

        return array_map([$this, 'hydrate'], $this->connection->fetchAllAssociative($sql, [
            'tenant' => $tenantId,
            'user' => $userId,
            'mailbox' => $mailAccountId,
        ]));
    }

    public function findVisibleById(
        string $id,
        int $tenantId,
        int $userId,
        array $mailAccountIds,
        bool $includeArchived = false,
    ): ?MailboxCalendarBinding {
        if (!self::validOpaqueId($id) || $mailAccountIds === []) {
            return null;
        }
        $sql = 'SELECT * FROM davyro_calendar_bindings '
            .'WHERE id = :id AND tenant_id = :tenant AND user_id = :user '
            .'AND mail_account_id IN (:mailboxes)';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $row = $this->connection->fetchAssociative($sql, [
            'id' => $id,
            'tenant' => $tenantId,
            'user' => $userId,
            'mailboxes' => array_values(array_unique(array_map('intval', $mailAccountIds))),
        ], ['mailboxes' => ArrayParameterType::INTEGER]);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveByUrl(
        string $calendarUrl,
        int $tenantId,
        int $userId,
        array $mailAccountIds,
    ): ?MailboxCalendarBinding {
        if ($mailAccountIds === []) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND user_id = :user AND calendar_url_hash = :url_hash '
            .'AND calendar_url = :url AND mail_account_id IN (:mailboxes) AND archived_at IS NULL',
            [
                'tenant' => $tenantId,
                'user' => $userId,
                'url_hash' => self::urlHash($calendarUrl),
                'url' => self::canonicalUrl($calendarUrl),
                'mailboxes' => array_values(array_unique(array_map('intval', $mailAccountIds))),
            ],
            ['mailboxes' => ArrayParameterType::INTEGER]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveByUrlForTenant(string $calendarUrl, int $tenantId): ?MailboxCalendarBinding
    {
        if ($tenantId < 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND calendar_url_hash = :url_hash AND calendar_url = :url '
            .'AND archived_at IS NULL',
            [
                'tenant' => $tenantId,
                'url_hash' => self::urlHash($calendarUrl),
                'url' => self::canonicalUrl($calendarUrl),
            ]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findActiveByIdForTenant(string $id, int $tenantId): ?MailboxCalendarBinding
    {
        if (!self::validOpaqueId($id) || $tenantId < 1) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM davyro_calendar_bindings '
            .'WHERE id = :id AND tenant_id = :tenant AND archived_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function updateCalendar(
        MailboxCalendarBinding $binding,
        string $name,
        string $color,
        ?bool $busyEnabled = null,
    ): MailboxCalendarBinding {
        $values = [
            'name' => trim($name),
            'color' => self::normalizeColor($color),
            'updated_at' => self::now(),
        ];
        if ($busyEnabled !== null) {
            $values['busy_enabled'] = $busyEnabled ? 1 : 0;
        }
        $this->connection->update('davyro_calendar_bindings', $values, ['id' => $binding->id()]);

        return $this->findByIdUnscoped($binding->id())
            ?? throw new \RuntimeException('Updated calendar binding disappeared');
    }

    public function deleteAdditional(MailboxCalendarBinding $binding): void
    {
        if ($binding->isPrimary()) {
            throw new \DomainException('The primary mailbox calendar cannot be deleted');
        }
        $this->connection->delete('davyro_calendar_bindings', ['id' => $binding->id()]);
    }

    /** @return MailboxCalendarBinding[] */
    public function archiveMailbox(int $tenantId, int $userId, int $mailAccountId, string $principal): array
    {
        $archivedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $purgeAfter = $archivedAt->modify('+30 days');
        $this->connection->executeStatement(
            'UPDATE davyro_calendar_bindings SET archived_at = :archived, purge_after = :purge, updated_at = :updated '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox '
            .'AND principal = :principal AND archived_at IS NULL',
            [
                'archived' => $archivedAt->format('Y-m-d H:i:s'),
                'purge' => $purgeAfter->format('Y-m-d H:i:s'),
                'updated' => $archivedAt->format('Y-m-d H:i:s'),
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
                'principal' => $principal,
            ]
        );

        return $this->findForMailbox($tenantId, $userId, $mailAccountId, true);
    }

    /** @return MailboxCalendarBinding[] */
    public function restoreMailbox(int $tenantId, int $userId, int $mailAccountId, string $principal): array
    {
        $this->connection->executeStatement(
            'UPDATE davyro_calendar_bindings SET archived_at = NULL, purge_after = NULL, updated_at = :updated '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox AND principal = :principal',
            [
                'updated' => self::now(),
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
                'principal' => $principal,
            ]
        );

        return $this->findForMailbox($tenantId, $userId, $mailAccountId, true);
    }

    /** @return MailboxCalendarBinding[] */
    public function findPurgeableMailbox(int $tenantId, int $userId, int $mailAccountId, string $principal): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox '
            .'AND principal = :principal AND purge_after IS NOT NULL AND purge_after <= :now',
            [
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
                'principal' => $principal,
                'now' => self::now(),
            ]
        );

        return array_map([$this, 'hydrate'], $rows);
    }

    public function purgeMailbox(int $tenantId, int $userId, int $mailAccountId, string $principal): int
    {
        return $this->connection->executeStatement(
            'DELETE FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox AND principal = :principal',
            [
                'tenant' => $tenantId,
                'user' => $userId,
                'mailbox' => $mailAccountId,
                'principal' => $principal,
            ]
        );
    }

    private function findExact(
        int $tenantId,
        int $userId,
        int $mailAccountId,
        string $principal,
        string $calendarUri,
        bool $includeArchived,
    ): ?MailboxCalendarBinding {
        $sql = 'SELECT * FROM davyro_calendar_bindings '
            .'WHERE tenant_id = :tenant AND user_id = :user AND mail_account_id = :mailbox '
            .'AND principal = :principal AND calendar_uri = :uri';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $row = $this->connection->fetchAssociative($sql, [
            'tenant' => $tenantId,
            'user' => $userId,
            'mailbox' => $mailAccountId,
            'principal' => $principal,
            'uri' => $calendarUri,
        ]);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    private function findByIdUnscoped(string $id): ?MailboxCalendarBinding
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM davyro_calendar_bindings WHERE id = :id',
            ['id' => $id]
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): MailboxCalendarBinding
    {
        return new MailboxCalendarBinding(
            (string) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (int) $row['mail_account_id'],
            (string) $row['principal'],
            (string) $row['calendar_uri'],
            (string) $row['calendar_url'],
            (string) $row['name'],
            (string) $row['color'],
            (string) $row['kind'],
            (bool) $row['is_primary'],
            (bool) $row['writable'],
            (bool) $row['busy_enabled'],
            self::date($row['archived_at'] ?? null),
            self::date($row['purge_after'] ?? null),
        );
    }

    public static function canonicalUrl(string $url): string
    {
        $url = trim($url);
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $url = $path;
        }

        return '/'.ltrim(rawurldecode(rtrim($url, '/')), '/').'/';
    }

    private static function urlHash(string $url): string
    {
        return hash('sha256', self::canonicalUrl($url));
    }

    private static function normalizeColor(string $color): string
    {
        $color = strtolower(trim($color));
        if (preg_match('/^#[0-9a-f]{6}([0-9a-f]{2})?$/', $color) !== 1) {
            throw new \InvalidArgumentException('Invalid calendar color');
        }

        return substr($color, 0, 7);
    }

    private static function validOpaqueId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id) === 1;
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
    }
}
