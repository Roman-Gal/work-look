<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120230000 extends AbstractMigration
{
    public function getDescription(): string { return 'Email verification and explicit permission to use the owner AI budget'; }
    public function up(Schema $schema): void
    {
        $before = $schema->getTable('app_user'); $after = clone $before;
        $after->addColumn('is_verified', 'boolean', ['default' => false]);
        $after->addColumn('live_ai_enabled', 'boolean', ['default' => false]);
        $after->addColumn('verification_hash', 'string', ['length' => 64, 'notnull' => false]);
        $after->addUniqueIndex(['verification_hash'], 'UNIQ_88BDF3E94E8C91BF');
        $after->addColumn('verification_expires_at', 'datetime_immutable', ['notnull' => false]);
        $diff = $this->connection->createSchemaManager()->createComparator()->compareTables($before, $after);
        foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($diff) as $sql) { $this->addSql($sql); }
        // Only pre-existing operator-created accounts are trusted. Future registrations default to false.
        $true = $this->connection->getDatabasePlatform()->convertBooleans(true);
        $this->addSql("UPDATE app_user SET is_verified = $true, live_ai_enabled = $true");
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Do not silently remove account verification. Restore a backup if rollback is needed.'); }
}
