<?php
namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Create a trusted, verified account with live AI permission.')]
final class CreateUserCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private UserPasswordHasherInterface $hasher) { parent::__construct(); }
    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED)->addOption('password-env', null, InputOption::VALUE_REQUIRED, 'Read password from a named environment variable.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output); $email = mb_strtolower(trim($input->getArgument('email')));
        if ($email === User::ADMIN_EMAIL) { $io->error('Use app:admin:provision for the reserved administrator identity.'); return Command::INVALID; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) { $io->error('Вкажіть коректний email.'); return Command::INVALID; }
        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email])) { $io->error('Користувач уже існує.'); return Command::INVALID; }
        $envName = $input->getOption('password-env');
        $password = $envName ? getenv($envName) : ($input->isInteractive() ? $io->askHidden('Пароль (щонайменше 14 символів)') : null);
        if (!is_string($password) || strlen($password) < 14 || strlen($password) > 128) { $io->error('Потрібен пароль довжиною 14–128 символів.'); return Command::INVALID; }
        $user = new User(); $user->email = $email; $user->password = $this->hasher->hashPassword($user, $password);
        $user->isVerified = true; $user->liveAiEnabled = true;
        $this->em->persist($user); $this->em->flush(); $io->success('Приватний акаунт створено: '.$email);
        return Command::SUCCESS;
    }
}
