<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/** Build platform-specific SQL from an immutable schema definition: SQLite, PostgreSQL, MySQL. */
final class Version20261120153213 extends AbstractMigration
{
    public function getDescription(): string { return 'Private accounts, canonical profiles, bounded agent runs, job matches and queue'; }
    public function up(Schema $schema): void
    {
        $target = new Schema();
        $user = $this->table($target, 'app_user');
        $this->string($user, 'email', 180); $user->addUniqueIndex(['email'], 'UNIQ_88BDF3E9E7927C74');
        $this->string($user, 'password'); $this->date($user, 'created_at');
        $profile = $this->table($target, 'profile');
        foreach (['display_name' => 120, 'headline' => 200, 'contact_line' => 300, 'search_terms' => 120] as $name => $length) { $this->string($profile, $name, $length); }
        $this->text($profile, 'facts_text'); $this->text($profile, 'preferences'); $this->date($profile, 'updated_at');
        $profile->addColumn('user_id', 'integer'); $profile->addUniqueIndex(['user_id'], 'UNIQ_8157AA0FA76ED395');
        $profile->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $run = $target->createTable('agent_run'); $this->string($run, 'id', 32); $run->setPrimaryKey(['id']);
        $this->string($run, 'status', 24); $this->string($run, 'mode', 20);
        foreach (['request_text', 'summary'] as $name) { $this->text($run, $name); }
        foreach (['profile_snapshot', 'sources', 'events'] as $name) { $run->addColumn($name, 'json'); }
        foreach (['model_calls', 'tool_calls', 'total_tokens', 'minimum_score', 'user_id'] as $name) { $run->addColumn($name, 'integer'); }
        $run->addColumn('cancel_requested', 'boolean'); $this->date($run, 'created_at'); $this->date($run, 'started_at', true); $this->date($run, 'finished_at', true);
        $run->addIndex(['user_id', 'created_at'], 'IDX_AC4401AAA76ED3958B8E8428'); $run->addIndex(['user_id'], 'IDX_AC4401AAA76ED395');
        $run->addForeignKeyConstraint('app_user', ['user_id'], ['id'], ['onDelete' => 'CASCADE']);
        $job = $this->table($target, 'job');
        foreach (['source' => 24, 'external_id' => 512, 'title' => 400, 'company' => 250, 'url' => 1500, 'work_mode' => 20, 'completeness' => 20, 'content_hash' => 64] as $name => $length) { $this->string($job, $name, $length); }
        $this->text($job, 'description'); foreach (['locations', 'salaries', 'skills'] as $name) { $job->addColumn($name, 'json'); }
        $this->date($job, 'expires_at', true); $this->date($job, 'last_seen_at'); $job->addUniqueIndex(['source', 'external_id'], 'job_source_identity');
        $match = $this->table($target, 'job_match'); $match->addColumn('score', 'integer'); $this->string($match, 'status', 16);
        foreach (['reasons', 'gaps', 'selected_fact_ids', 'job_snapshot'] as $name) { $match->addColumn($name, 'json'); }
        $this->date($match, 'created_at'); $this->string($match, 'run_id', 32); $match->addColumn('job_id', 'integer');
        $match->addUniqueIndex(['run_id', 'job_id'], 'match_run_job');
        $match->addIndex(['run_id'], 'IDX_7B07787584E3FEC4'); $match->addIndex(['job_id'], 'IDX_7B077875BE04EA9');
        $match->addForeignKeyConstraint('agent_run', ['run_id'], ['id'], ['onDelete' => 'CASCADE']);
        $match->addForeignKeyConstraint('job', ['job_id'], ['id']);
        $queue = $this->table($target, 'messenger_messages', 'bigint');
        $this->text($queue, 'body'); $this->text($queue, 'headers'); $this->string($queue, 'queue_name', 190);
        $this->date($queue, 'created_at'); $this->date($queue, 'available_at'); $this->date($queue, 'delivered_at', true);
        $queue->addIndex(['queue_name', 'available_at', 'delivered_at', 'id'], 'IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750');
        foreach ($target->toSql($this->connection->getDatabasePlatform()) as $sql) { $this->addSql($sql); }
    }
    public function down(Schema $schema): void
    {
        foreach (['job_match', 'profile', 'agent_run', 'job', 'app_user', 'messenger_messages'] as $table) { $this->addSql($this->connection->getDatabasePlatform()->getDropTableSQL($table)); }
    }
    private function table(Schema $schema, string $name, string $idType = 'integer'): Table
    {
        $table = $schema->createTable($name); $table->addColumn('id', $idType, ['autoincrement' => true]); $table->setPrimaryKey(['id']); return $table;
    }
    private function string(Table $table, string $name, int $length = 255): void { $table->addColumn($name, 'string', ['length' => $length]); }
    private function text(Table $table, string $name): void { $table->addColumn($name, 'text'); }
    private function date(Table $table, string $name, bool $nullable = false): void { $table->addColumn($name, 'datetime_immutable', ['notnull' => !$nullable]); }
}
