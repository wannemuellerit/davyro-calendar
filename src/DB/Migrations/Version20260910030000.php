<?php

declare(strict_types=1);

namespace AgenDAV\DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ensures the durable iMIP dispatch outbox also exists on upgraded WIP installations. */
final class Version20260910030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the durable Davyro calendar iMIP dispatch outbox';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('davyro_imip_dispatch_outbox')) {
            return;
        }
        $outbox = $schema->createTable('davyro_imip_dispatch_outbox');
        $outbox->addColumn('id', 'string', ['length' => 36]);
        $outbox->addColumn('tenant_id', 'bigint', ['unsigned' => true]);
        $outbox->addColumn('user_id', 'bigint', ['unsigned' => true]);
        $outbox->addColumn('mail_account_id', 'bigint', ['unsigned' => true]);
        $outbox->addColumn('event_uid', 'string', ['length' => 512]);
        $outbox->addColumn('method', 'string', ['length' => 16]);
        $outbox->addColumn('message', 'text', ['length' => 16_777_215]);
        $outbox->addColumn('status', 'string', ['length' => 16]);
        $outbox->addColumn('attempt_count', 'integer', ['unsigned' => true, 'default' => 0]);
        $outbox->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => false]);
        $outbox->addColumn('last_error_code', 'string', ['length' => 64, 'notnull' => false]);
        $outbox->addColumn('suspended_at', 'datetime_immutable', ['notnull' => false]);
        $outbox->addColumn('created_at', 'datetime_immutable');
        $outbox->addColumn('updated_at', 'datetime_immutable');
        $outbox->addColumn('sent_at', 'datetime_immutable', ['notnull' => false]);
        $outbox->setPrimaryKey(['id']);
        $outbox->addIndex(['status', 'next_attempt_at', 'suspended_at'], 'idx_imip_dispatch_due');
        $outbox->addIndex(
            ['tenant_id', 'user_id', 'mail_account_id', 'created_at'],
            'idx_imip_dispatch_context'
        );
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('davyro_imip_dispatch_outbox')) {
            $schema->dropTable('davyro_imip_dispatch_outbox');
        }
    }
}
