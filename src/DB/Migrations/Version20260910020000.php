<?php

declare(strict_types=1);

namespace AgenDAV\DB\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Compatibility migration for installations that ran the WebCal WIP migration. */
final class Version20260910020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add authenticated ciphertext and safe URL hint to WebCal feed state';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('davyro_webcal_feed_states')) {
            return;
        }
        $table = $schema->getTable('davyro_webcal_feed_states');
        if (!$table->hasColumn('encrypted_url')) {
            $table->addColumn('encrypted_url', 'text', ['notnull' => false]);
        }
        if (!$table->hasColumn('url_hint')) {
            $table->addColumn('url_hint', 'string', ['length' => 255, 'notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('davyro_webcal_feed_states')) {
            return;
        }
        $table = $schema->getTable('davyro_webcal_feed_states');
        if ($table->hasColumn('encrypted_url')) {
            $table->dropColumn('encrypted_url');
        }
        if ($table->hasColumn('url_hint')) {
            $table->dropColumn('url_hint');
        }
    }
}
