<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Singleton administrator and structured WorkLook activity records'; }
    public function up(Schema $schema): void
    {
        $before = $schema->getTable('app_user'); $after = clone $before;
        $after->addColumn('admin_key', 'string', ['length' => 32, 'notnull' => false]);
        $after->addUniqueIndex(['admin_key'], 'singleton_admin_key');
        $platform = $this->connection->getDatabasePlatform();
        $diff = $this->connection->createSchemaManager()->createComparator()->compareTables($before, $after);
        foreach ($platform->getAlterTableSQL($diff) as $sql) { $this->addSql($sql); }
        $table = new \Doctrine\DBAL\Schema\Table('activity_event');
        $table->addColumn('id', 'string', ['length' => 32]); $table->setPrimaryKey(['id']);
        $table->addColumn('recorded_at', 'datetime_immutable');
        foreach (['kind' => 32, 'request_id' => 32, 'route_name' => 80, 'path' => 255, 'method' => 8] as $name => $length) {
            $table->addColumn($name, 'string', ['length' => $length]);
        }
        foreach (['actor_id' => 64, 'actor_name' => 180, 'ip' => 45, 'country' => 2, 'city' => 120, 'referrer_host' => 255] as $name => $length) {
            $table->addColumn($name, 'string', ['length' => $length, 'notnull' => false]);
        }
        $table->addColumn('status_code', 'integer'); $table->addColumn('duration_ms', 'integer'); $table->addColumn('context', 'json');
        $table->addIndex(['recorded_at'], 'activity_time_idx');
        $table->addIndex(['actor_id', 'recorded_at'], 'activity_actor_idx');
        $table->addIndex(['kind', 'recorded_at'], 'activity_kind_time_idx');
        foreach ($platform->getCreateTableSQL($table) as $sql) { $this->addSql($sql); }
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Activity history and the administrator identity must be retained.'); }
}
