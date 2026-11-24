<?php
declare(strict_types=1);
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:provision', description: 'Provision the single fixed administrator. Never prints the password.')]
final class ProvisionAdminCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addOption('password-env', null, InputOption::VALUE_REQUIRED, 'Read the initial password from this environment variable. Existing passwords are preserved.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => User::ADMIN_EMAIL]);
        if ($user?->isAdministrator()) { $output->writeln('Administrator already provisioned; password unchanged.'); return Command::SUCCESS; }
        $variable = $input->getOption('password-env'); $password = is_string($variable) ? getenv($variable) : false;
        if (!is_string($password) || strlen($password) < 15 || strlen($password) > 128) {
            $output->writeln('Set --password-env to a private environment variable containing 15–128 characters.'); return Command::INVALID;
        }
        if ($user !== null) {
            $output->writeln('Reserved email already exists without the administrator marker. Refusing an implicit takeover.'); return Command::FAILURE;
        }
        $user = new User(); $user->email = User::ADMIN_EMAIL; $user->adminKey = User::ADMIN_KEY;
        $user->password = $this->hasher->hashPassword($user, $password); $user->isVerified = true; $user->liveAiEnabled = true;
        $this->em->persist($user); $this->em->flush();
        $output->writeln('Singleton administrator provisioned: '.User::ADMIN_EMAIL);
        return Command::SUCCESS;
    }
}
