<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261125140000 extends AbstractMigration
{
    public function getDescription(): string { return 'Private database PDF storage, privacy acknowledgement and operator-processed work requests'; }
    public function up(Schema $schema): void
    {
        // Schema diffs keep PostgreSQL BYTEA and SQLite BLOB portable.
        $before = new Schema(); $draft = $before->createTable('resume_draft'); $draft->addColumn('id', 'integer');
        $after = clone $before; $draft = $after->getTable('resume_draft');
        $draft->addColumn('pdf_data', 'blob', ['notnull'=>false]);
        $draft->addColumn('privacy_acknowledged_at', 'datetime_immutable', ['notnull'=>false]);
        $draft->addColumn('privacy_version', 'string', ['length'=>24,'notnull'=>false]);
        $platform = $this->connection->getDatabasePlatform();
        $comparator = $this->connection->createSchemaManager()->createComparator();
        foreach ($platform->getAlterSchemaSQL($comparator->compareSchemas($before, $after)) as $sql) { $this->addSql($sql); }
        $target = new Schema(); $table = $target->createTable('local_work_request');
        $table->addColumn('id','string',['length'=>32]); $table->setPrimaryKey(['id']);
        $table->addColumn('user_id','integer'); $table->addForeignKeyConstraint('app_user',['user_id'],['id'],['onDelete'=>'CASCADE']);
        $table->addColumn('active_user_key','integer',['notnull'=>false]); $table->addUniqueIndex(['active_user_key'],'UNIQ_LOCAL_WORK_ACTIVE');
        $table->addColumn('status','string',['length'=>16]); $table->addColumn('locale','string',['length'=>2]);
        $table->addColumn('input','json'); $table->addColumn('pdf_data','blob');
        $table->addColumn('result','json',['notnull'=>false]); $table->addColumn('created_at','datetime_immutable');
        foreach (['completed_at','notification_sent_at'] as $name) { $table->addColumn($name,'datetime_immutable',['notnull'=>false]); }
        $table->addIndex(['user_id','created_at'],'local_work_owner_created');
        foreach ($platform->getCreateTableSQL($table) as $sql) { $this->addSql($sql); }
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Do not silently delete private CVs or work results.'); }
}
