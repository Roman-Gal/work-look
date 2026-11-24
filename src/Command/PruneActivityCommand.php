<?php
declare(strict_types=1);
namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:activity:prune', description: 'Remove activity older than 90 days, retaining administrator actions for 365 days.')]
final class PruneActivityCommand extends Command
{
    public function __construct(private Connection $db) { parent::__construct(); }
    protected function configure(): void { $this->addOption('dry-run', null, InputOption::VALUE_NONE); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = [(new \DateTimeImmutable('-90 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable('-365 days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s')];
        $where = "(kind <> 'admin_action' AND recorded_at < ?) OR (kind = 'admin_action' AND recorded_at < ?)";
        $count = $input->getOption('dry-run') ? $this->db->fetchOne('SELECT COUNT(*) FROM activity_event WHERE '.$where, $params)
            : $this->db->executeStatement('DELETE FROM activity_event WHERE '.$where, $params);
        $output->writeln(sprintf('%s %d expired activity records.', $input->getOption('dry-run') ? 'Would remove' : 'Removed', $count));
        return Command::SUCCESS;
    }
}
