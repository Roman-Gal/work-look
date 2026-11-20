<?php
namespace App\Command;

use Doctrine\DBAL\{Connection, ParameterType};
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Offline import of an operator-created, consistent SQLite backup into an EMPTY migrated PostgreSQL database. */
#[AsCommand(name: 'app:database:import-sqlite', description: 'Import a SQLite backup into empty PostgreSQL; verify every field and repair identity sequences.')]
final class ImportSqliteCommand extends Command
{
    private const TABLES = ['app_user', 'profile', 'agent_run', 'job', 'job_match', 'messenger_messages'];
    public function __construct(private Connection $connection) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('snapshot', InputArgument::REQUIRED, 'Path to a consistent SQLite backup. Stop all writers first.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $db = $this->connection;
        $path = realpath($input->getArgument('snapshot'));
        if (!$db->getDatabasePlatform() instanceof PostgreSQLPlatform || !$path || !is_file($path)) {
            $io->error('An existing SQLite snapshot and a PostgreSQL target are required.'); return Command::INVALID;
        }
        $source = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $source->exec('PRAGMA query_only = ON');
        if ($source->query('PRAGMA integrity_check')->fetchColumn() !== 'ok' || $source->query('PRAGMA foreign_key_check')->fetch()) {
            $io->error('SQLite integrity checks failed.'); return Command::FAILURE;
        }
        $schema = $db->createSchemaManager()->introspectSchema(); $counts = [];
        $db->beginTransaction();
        try {
            $db->executeQuery('SELECT pg_advisory_xact_lock(740201120)');
            foreach (self::TABLES as $name) {
                $db->executeStatement('LOCK TABLE '.$db->quoteIdentifier($name).' IN ACCESS EXCLUSIVE MODE');
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM '.$db->quoteIdentifier($name)) !== 0) { throw new \RuntimeException('Target must be empty.'); }
            }
            foreach (self::TABLES as $name) {
                $table = $schema->getTable($name); $counts[$name] = 0;
                foreach ($source->query('SELECT * FROM "'.$name.'" ORDER BY id', \PDO::FETCH_ASSOC) as $row) {
                    // Old accounts were created by the operator, before public registration existed.
                    if ($name === 'app_user') { $row += ['is_verified' => true, 'live_ai_enabled' => true]; }
                    $types = [];
                    foreach ($row as $column => &$value) {
                        if (!$table->hasColumn($column)) { throw new \RuntimeException('Unknown source column.'); }
                        $type = $table->getColumn($column)->getType();
                        if ($value === null) { $types[$column] = ParameterType::NULL; }
                        elseif ($type instanceof \Doctrine\DBAL\Types\BooleanType) { $value = (bool) $value; $types[$column] = ParameterType::BOOLEAN; }
                        elseif ($type instanceof \Doctrine\DBAL\Types\IntegerType || $type instanceof \Doctrine\DBAL\Types\BigIntType) { $value = (int) $value; $types[$column] = ParameterType::INTEGER; }
                        else { $types[$column] = ParameterType::STRING; }
                    }
                    unset($value);
                    $db->insert($name, $row, $types); ++$counts[$name];
                    $actual = $db->fetchAssociative('SELECT * FROM '.$db->quoteIdentifier($name).' WHERE id = ?', [$row['id']]);
                    foreach ($row as $column => $expected) {
                        $type = $table->getColumn($column)->getType();
                        if ($this->normalize($type, $expected) !== $this->normalize($type, $actual[$column])) {
                            throw new \RuntimeException('Imported value differs.');
                        }
                    }
                }
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM '.$db->quoteIdentifier($name)) !== $counts[$name]) { throw new \RuntimeException('Row count differs.'); }
                if ($table->getColumn('id')->getAutoincrement()) {
                    $sequence = $db->fetchOne('SELECT pg_get_serial_sequence(?, ?)', [$name, 'id']);
                    if (!$sequence) { throw new \RuntimeException('Missing identity sequence.'); }
                    $max = (int) $db->fetchOne('SELECT COALESCE(MAX(id), 0) FROM '.$db->quoteIdentifier($name));
                    $db->executeQuery('SELECT setval(?::regclass, ?, ?)', [$sequence, max(1, $max), $max > 0], [ParameterType::STRING, ParameterType::INTEGER, ParameterType::BOOLEAN]);
                }
            }
            $db->commit();
        } catch (\Throwable $error) {
            $db->rollBack(); $io->error('Import refused or rolled back. No row values or credentials are printed. Error type: '.$error::class); return Command::FAILURE;
        }
        foreach ($counts as $name => $count) { $io->writeln($name.': '.$count.' verified rows'); }
        $io->success('All source fields, counts, foreign keys and identity sequences verified.'); return Command::SUCCESS;
    }
    private function normalize(\Doctrine\DBAL\Types\Type $type, mixed $value): mixed
    {
        if ($value === null) { return null; }
        if ($type instanceof \Doctrine\DBAL\Types\BooleanType) { return in_array($value, [true, 1, '1', 't', 'true'], true); }
        if ($type instanceof \Doctrine\DBAL\Types\IntegerType || $type instanceof \Doctrine\DBAL\Types\BigIntType) { return (string) $value; }
        if ($type instanceof \Doctrine\DBAL\Types\JsonType) { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); }
        if ($type instanceof \Doctrine\DBAL\Types\DateTimeImmutableType) { return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s.u'); }
        return (string) $value;
    }
}
