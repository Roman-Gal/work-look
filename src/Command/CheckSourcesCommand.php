<?php
namespace App\Command;

use App\Source\{SolidJobsSource, RssJobsSource};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:sources:check', description: 'One manual read-only API/RSS smoke check; no model calls or DB writes.')]
final class CheckSourcesCommand extends Command
{
    public function __construct(private SolidJobsSource $solid, private RssJobsSource $rss) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('source', InputArgument::REQUIRED, 'solidjobs, dou or djinni')->addArgument('keywords', InputArgument::OPTIONAL, 'Search terms', 'PHP'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $source = $input->getArgument('source'); $query = $input->getArgument('keywords');
        if (!in_array($source, ['solidjobs', 'dou', 'djinni'], true) || mb_strlen($query) > 120) { $io->error('Некоректне джерело або пошуковий запит.'); return Command::INVALID; }
        try { $data = $source === 'solidjobs' ? $this->solid->search($query, 0) : $this->rss->search($source, $query, 0); }
        catch (\Throwable $error) { $io->error($error->getMessage()); return Command::FAILURE; }
        $io->success(sprintf('%s: %d нормалізованих вакансій; coverage=%s. Без запису в БД.', $source, count($data['jobs']), $data['coverage']));
        return Command::SUCCESS;
    }
}
