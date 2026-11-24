<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124180000 extends AbstractMigration
{
    public function getDescription(): string { return 'Private text-PDF drafts and reviewed job-search knowledge with owner-scoped prompts'; }
    public function up(Schema $schema): void
    {
        $target = new Schema();
        $resume = $target->createTable('resume_draft');
        $resume->addColumn('id', 'integer', ['autoincrement' => true]); $resume->setPrimaryKey(['id']);
        $resume->addColumn('user_id', 'integer'); $resume->addUniqueIndex(['user_id'], 'UNIQ_RESUME_USER');
        $resume->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $resume->addColumn('filename', 'string', ['length' => 180]); $resume->addColumn('content_hash', 'string', ['length' => 64]);
        foreach (['text', 'request_text', 'preferences'] as $name) { $resume->addColumn($name, 'text'); }
        $resume->addColumn('pages', 'integer'); $resume->addColumn('prepared_profile', 'json', ['notnull' => false]);
        $resume->addColumn('updated_at', 'datetime_immutable');
        $entry = $target->createTable('knowledge_entry');
        $entry->addColumn('id', 'integer', ['autoincrement' => true]); $entry->setPrimaryKey(['id']);
        $entry->addColumn('seed_key', 'string', ['length' => 64, 'notnull' => false]); $entry->addUniqueIndex(['seed_key'], 'UNIQ_KNOWLEDGE_SEED');
        $entry->addColumn('owner_id', 'integer', ['notnull' => false]);
        $entry->addForeignKeyConstraint('app_user', ['owner_id'], ['id'], ['onDelete' => 'CASCADE']);
        foreach (['title' => 180, 'kind' => 24, 'site' => 24, 'locale' => 2, 'status' => 16, 'source_url' => 1000] as $name => $length) { $entry->addColumn($name, 'string', ['length' => $length]); }
        foreach (['content', 'source_notes'] as $name) { $entry->addColumn($name, 'text'); }
        $entry->addColumn('revision', 'integer', ['default' => 1]);
        foreach (['created_at', 'updated_at', 'reviewed_at'] as $name) { $entry->addColumn($name, 'datetime_immutable', ['notnull' => $name !== 'reviewed_at']); }
        $entry->addIndex(['owner_id', 'status', 'site'], 'knowledge_scope_idx'); $entry->addIndex(['owner_id'], 'IDX_KNOWLEDGE_OWNER');
        $platform = $this->connection->getDatabasePlatform();
        foreach ([$resume, $entry] as $table) { foreach ($platform->getCreateTableSQL($table) as $sql) { $this->addSql($sql); } }
    }
    public function down(Schema $schema): void { $this->throwIrreversibleMigrationException('Private resume sources and curated knowledge must not be silently erased.'); }
}
