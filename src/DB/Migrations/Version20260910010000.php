<?php

declare(strict_types=1);

namespace AgenDAV\DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260910010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add calendar publications, mailbox availability and persistent WebCal cache state';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('davyro_calendar_publications')) {
            $table = $schema->createTable('davyro_calendar_publications');
            $table->addColumn('id', 'string', ['length' => 36]);
            $table->addColumn('tenant_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('user_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('mail_account_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('calendar_id', 'string', ['length' => 255]);
            $table->addColumn('token_hash', 'string', ['length' => 64, 'fixed' => true]);
            $table->addColumn('created_at', 'datetime_immutable');
            $table->addColumn('revoked_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('suspended_at', 'datetime_immutable', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['token_hash'], 'uniq_calendar_publication_token');
            $table->addIndex(
                ['tenant_id', 'user_id', 'calendar_id', 'revoked_at'],
                'idx_calendar_publication_owner'
            );
            $table->addIndex(['mail_account_id', 'revoked_at'], 'idx_calendar_publication_mailbox');
        }

        if (!$schema->hasTable('davyro_mailbox_availability')) {
            $table = $schema->createTable('davyro_mailbox_availability');
            $table->addColumn('id', 'string', ['length' => 36]);
            $table->addColumn('tenant_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('user_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('mail_account_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('timezone', 'string', ['length' => 64]);
            $table->addColumn('weekly_windows', 'json');
            $table->addColumn('exceptions', 'json');
            $table->addColumn('updated_at', 'datetime_immutable');
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['tenant_id', 'user_id', 'mail_account_id'],
                'uniq_mailbox_availability'
            );
        }

        if (!$schema->hasTable('davyro_webcal_feed_states')) {
            $table = $schema->createTable('davyro_webcal_feed_states');
            $table->addColumn('id', 'string', ['length' => 36]);
            $table->addColumn('tenant_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('user_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('mail_account_id', 'bigint', ['unsigned' => true]);
            $table->addColumn('subscription_id', 'string', ['length' => 255]);
            $table->addColumn('encrypted_url', 'text');
            $table->addColumn('url_hint', 'string', ['length' => 255]);
            $table->addColumn('status', 'string', ['length' => 16]);
            $table->addColumn('etag', 'string', ['length' => 512, 'notnull' => false]);
            $table->addColumn('last_modified', 'string', ['length' => 128, 'notnull' => false]);
            $table->addColumn('cached_body', 'text', ['length' => 16_777_215, 'notnull' => false]);
            $table->addColumn('last_attempt_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('last_success_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('next_refresh_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('stale_until', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('last_error', 'string', ['length' => 64, 'notnull' => false]);
            $table->addColumn('suspended_at', 'datetime_immutable', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(
                ['tenant_id', 'user_id', 'subscription_id'],
                'uniq_webcal_subscription_state'
            );
            $table->addIndex(['next_refresh_at'], 'idx_webcal_next_refresh');
            $table->addIndex(['mail_account_id', 'status'], 'idx_webcal_mailbox_status');
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'davyro_webcal_feed_states',
            'davyro_mailbox_availability',
            'davyro_calendar_publications',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }
}
