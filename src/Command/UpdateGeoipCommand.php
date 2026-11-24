<?php
declare(strict_types=1);
namespace App\Command;

use MaxMind\Db\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:geoip:update', description: 'Install the monthly DB-IP Country Lite database locally (CC BY 4.0). No visitor IPs are transmitted.')]
final class UpdateGeoipCommand extends Command
{
    public function __construct(private HttpClientInterface $http, private LockFactory $locks,
        #[Autowire('%kernel.project_dir%/var/geoip')] private string $directory) { parent::__construct(); }
    protected function configure(): void { $this->addOption('month', null, InputOption::VALUE_REQUIRED, 'Release YYYY-MM; defaults to the current UTC month.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $month = $input->getOption('month') ?? gmdate('Y-m');
        if (!is_string($month) || !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month)) { return Command::INVALID; }
        $lock = $this->locks->createLock('worklook-geoip-update', 180);
        if (!$lock->acquire()) { $output->writeln('GeoIP update already running.'); return Command::FAILURE; }
        $temporary = null;
        try {
            $response = $this->http->request('GET', 'https://download.db-ip.com/free/dbip-country-lite-'.$month.'.mmdb.gz', [
                'timeout' => 30, 'max_duration' => 90, 'max_redirects' => 0, 'buffer' => false]);
            if ($response->getStatusCode() !== 200) { throw new \RuntimeException('Database download unavailable.'); }
            $compressed = '';
            foreach ($this->http->stream($response) as $chunk) {
                $compressed .= $chunk->getContent();
                if (strlen($compressed) > 15_000_000) { $response->cancel(); throw new \RuntimeException('Database download too large.'); }
            }
            $data = @gzdecode($compressed, 30_000_000);
            if ($data === false || strlen($data) < 1000) { throw new \RuntimeException('Invalid database archive.'); }
            if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) { throw new \RuntimeException('Cannot create private GeoIP directory.'); }
            $temporary = $this->directory.'/location-'.bin2hex(random_bytes(8)).'.tmp';
            if (file_put_contents($temporary, $data, LOCK_EX) !== strlen($data)) { throw new \RuntimeException('Cannot write GeoIP database.'); }
            chmod($temporary, 0600);
            $reader = new Reader($temporary);
            try {
                if (!str_contains(strtolower($reader->metadata()->databaseType), 'country')) { throw new \RuntimeException('Unexpected database type.'); }
                $reader->get('8.8.8.8');
            } finally { $reader->close(); }
            if (!rename($temporary, $this->directory.'/location.mmdb')) { throw new \RuntimeException('Cannot activate GeoIP database.'); }
            $output->writeln('Installed DB-IP Country Lite '.$month.'. Attribution: https://db-ip.com (CC BY 4.0).');
            return Command::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('GeoIP update failed; the previous database was preserved.'); return Command::FAILURE;
        } finally {
            if ($temporary !== null && is_file($temporary)) { unlink($temporary); }
            $lock->release();
        }
    }
}
