<?php

declare(strict_types=1);

namespace AgenDAV\DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist opaque tenant/user/mailbox ownership metadata for Davyro calendars';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('davyro_calendar_bindings')) {
            $table = $schema->createTable('davyro_calendar_bindings');
            $table->addColumn('id', 'string', ['length' => 36]);
            $table->addColumn('tenant_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('user_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('mail_account_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('principal', 'string', ['length' => 191]);
            $table->addColumn('calendar_uri', 'string', ['length' => 191]);
            $table->addColumn('calendar_url', 'text');
            $table->addColumn('calendar_url_hash', 'string', ['length' => 64, 'fixed' => true]);
            $table->addColumn('name', 'string', ['length' => 160]);
            $table->addColumn('color', 'string', ['length' => 9, 'default' => '#6875F5']);
            $table->addColumn('kind', 'string', ['length' => 32]);
            $table->addColumn('is_primary', 'boolean', ['default' => false]);
            $table->addColumn('writable', 'boolean', ['default' => true]);
            $table->addColumn('busy_enabled', 'boolean', ['default' => true]);
            $table->addColumn('archived_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('purge_after', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('created_at', 'datetime_immutable');
            $table->addColumn('updated_at', 'datetime_immutable');
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['tenant_id', 'user_id', 'mail_account_id', 'principal', 'calendar_uri'],
                'uniq_davyro_mailbox_calendar'
            );
            $table->addIndex(
                ['tenant_id', 'user_id', 'calendar_url_hash'],
                'idx_davyro_calendar_access'
            );
            $table->addIndex(['purge_after'], 'idx_davyro_calendar_purge');
        }

        if (!$schema->hasTable('davyro_internal_nonces')) {
            $nonces = $schema->createTable('davyro_internal_nonces');
            $nonces->addColumn('nonce_hash', 'string', ['length' => 64, 'fixed' => true]);
            $nonces->addColumn('expires_at', 'datetime_immutable');
            $nonces->setPrimaryKey(['nonce_hash']);
            $nonces->addIndex(['expires_at'], 'idx_davyro_nonce_expiry');
        }

        if (!$schema->hasTable('davyro_mailbox_lifecycle_state')) {
            $lifecycle = $schema->createTable('davyro_mailbox_lifecycle_state');
            $lifecycle->addColumn('tenant_id', 'bigint', ['unsigned' => true]);
            $lifecycle->addColumn('user_id', 'bigint', ['unsigned' => true]);
            $lifecycle->addColumn('mail_account_id', 'bigint', ['unsigned' => true]);
            $lifecycle->addColumn('principal', 'string', ['length' => 191]);
            $lifecycle->addColumn('lifecycle_version', 'bigint', ['unsigned' => true]);
            $lifecycle->addColumn('last_action', 'string', ['length' => 16]);
            $lifecycle->addColumn('updated_at', 'datetime_immutable');
            $lifecycle->setPrimaryKey(['tenant_id', 'user_id', 'mail_account_id']);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('davyro_calendar_bindings')) {
            $schema->dropTable('davyro_calendar_bindings');
        }
        if ($schema->hasTable('davyro_internal_nonces')) {
            $schema->dropTable('davyro_internal_nonces');
        }
        if ($schema->hasTable('davyro_mailbox_lifecycle_state')) {
            $schema->dropTable('davyro_mailbox_lifecycle_state');
        }
    }
}
