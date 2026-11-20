<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120235900 extends AbstractMigration
{
    public function getDescription(): string { return 'One durable complimentary AI search per account; paid searches reserved for later'; }
    public function up(Schema $schema): void
    {
        $before = $schema->getTable('app_user'); $after = clone $before;
        $after->addColumn('free_search_run_id', 'string', ['length' => 32, 'notnull' => false]);
        $after->getColumn('live_ai_enabled')->setDefault(true);
        $diff = $this->connection->createSchemaManager()->createComparator()->compareTables($before, $after);
        foreach ($this->connection->getDatabasePlatform()->getAlterTableSQL($diff) as $sql) { $this->addSql($sql); }
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Do not silently reset consumed free searches.'); }
}
