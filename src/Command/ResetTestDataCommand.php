<?php
namespace App\Command;

use Doctrine\DBAL\{Connection, ParameterType};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:reset-test-data', description: 'Erase WorkLook test data while preserving exactly the requested demo login. Stop workers first.')]
final class ResetTestDataCommand extends Command
{
    public function __construct(private Connection $connection) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('keep-user', null, InputOption::VALUE_REQUIRED, 'The exact demo email to preserve.');
        $this->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Must equal RESET_WORKLOOK_TEST_DATA.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('confirm') !== 'RESET_WORKLOOK_TEST_DATA' || !filter_var($input->getOption('keep-user'), FILTER_VALIDATE_EMAIL)) {
            $output->writeln('Refused: specify --keep-user and --confirm=RESET_WORKLOOK_TEST_DATA.'); return Command::INVALID;
        }
        $db = $this->connection;
        $db->transactional(function () use ($db, $input): void {
            $id = $db->fetchOne('SELECT id FROM app_user WHERE email = ?', [$input->getOption('keep-user')]);
            if ($id === false) { throw new \RuntimeException('Demo account not found; nothing erased.'); }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM app_user WHERE admin_key IS NOT NULL AND id <> ?', [$id]) > 0) {
                throw new \RuntimeException('Reset would delete the singleton administrator; nothing erased.');
            }
            // Dependency order, no CASCADE outside these explicit WorkLook tables/databases.
            foreach (['messenger_messages', 'local_work_request', 'resume_draft', 'job_match', 'profile', 'agent_run', 'job'] as $table) {
                if ($table === 'local_work_request' && !$db->createSchemaManager()->tablesExist([$table])) { continue; }
                if ($table === 'messenger_messages' && !$db->createSchemaManager()->tablesExist([$table])) { continue; }
                if ($table === 'resume_draft' && !$db->createSchemaManager()->tablesExist([$table])) { continue; }
                $db->executeStatement('DELETE FROM '.$table);
            }
            if ($db->createSchemaManager()->tablesExist(['knowledge_entry'])) { $db->executeStatement('DELETE FROM knowledge_entry WHERE owner_id IS NOT NULL'); }
            $db->executeStatement('DELETE FROM app_user WHERE id <> ?', [$id]);
            $db->executeStatement('UPDATE app_user SET free_search_run_id = NULL, verification_hash = NULL, verification_expires_at = NULL, is_verified = ?, live_ai_enabled = ? WHERE id = ?',
                [true, true, $id], [ParameterType::BOOLEAN, ParameterType::BOOLEAN, ParameterType::INTEGER]);
        });
        $output->writeln('WorkLook test data erased. Demo email/password preserved; one free search available.');
        return Command::SUCCESS;
    }
}
