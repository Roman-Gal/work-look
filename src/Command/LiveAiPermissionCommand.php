<?php
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:user:live-ai', description: 'Explicitly enable or disable an account access to the owner AI budget.')]
final class LiveAiPermissionCommand extends Command
{
    public function __construct(private EntityManagerInterface $em) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('email', InputArgument::REQUIRED)->addArgument('permission', InputArgument::REQUIRED, 'on or off'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => mb_strtolower(trim($input->getArgument('email')))]);
        $permission = $input->getArgument('permission');
        if (!$user || !$user->isVerified || !in_array($permission, ['on', 'off'], true)) {
            $output->writeln('A verified account and permission on/off are required.'); return Command::INVALID;
        }
        $user->liveAiEnabled = $permission === 'on'; $this->em->flush();
        $output->writeln('Live AI permission updated.'); return Command::SUCCESS;
    }
}
