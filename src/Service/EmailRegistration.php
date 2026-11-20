<?php
namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailRegistration
{
    public function __construct(
        private EntityManagerInterface $em, private MailerInterface $mailer,
        private UserPasswordHasherInterface $hasher, private TranslatorInterface $translator,
        private LockFactory $locks, private LoggerInterface $logger,
        #[Autowire('%env(bool:REGISTRATION_ENABLED)%')] private bool $enabled,
        #[Autowire('%env(MAILER_DSN)%')] private string $dsn,
        #[Autowire('%env(MAILER_FROM)%')] private string $from,
        #[Autowire('%env(DEFAULT_URI)%')] private string $origin,
    ) {}

    public function isEnabled(): bool
    {
        $parts = parse_url($this->origin);
        return $this->enabled && filter_var($this->from, FILTER_VALIDATE_EMAIL)
            && preg_match('~^smtps?://~', $this->dsn)
            && $parts && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && (($parts['scheme'] ?? '') === 'https' || (($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true)));
    }

    /** Never disclose whether an address already has an account. Password is chosen only after email ownership. */
    public function request(string $email, string $locale): void
    {
        if (!$this->isEnabled()) { return; }
        $lock = $this->locks->createLock('register_'.hash('sha256', $email), 30);
        if (!$lock->acquire()) { return; }
        try {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($user?->isVerified) { return; }
            if (!$user) {
                $user = new User(); $user->email = $email;
                $user->password = $this->hasher->hashPassword($user, bin2hex(random_bytes(32)));
                $this->em->persist($user);
            }
            $token = bin2hex(random_bytes(32));
            $user->verificationHash = hash('sha256', $token);
            $user->verificationExpiresAt = new \DateTimeImmutable('+1 hour');
            $this->em->flush();
            // A URL fragment never reaches the HTTP server/access log. The browser copies it into the POST form.
            // DEFAULT_URI is operator configuration, never the untrusted incoming Host header.
            $link = rtrim($this->origin, '/').'/verify-email#token='.$token;
            $subject = $this->translator->trans('registration.mail_subject', [], null, $locale);
            $body = $this->translator->trans('registration.mail_body', ['%link%' => $link, '%token%' => $token], null, $locale);
            try {
                $this->mailer->send((new Email())->from($this->from)->to($email)->subject($subject)->text($body));
            } catch (\Throwable) {
                // Do not log the SMTP DSN, recipient, message or verification token.
                $user->verificationHash = null; $user->verificationExpiresAt = null; $this->em->flush();
                $this->logger->error('Registration email could not be delivered. Check the private SMTP configuration.');
            }
        } finally { $lock->release(); }
    }

    public function confirm(#[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): bool
    {
        if (!$this->isEnabled() || !preg_match('/^[a-f0-9]{64}$/D', $token)) { return false; }
        $hash = hash('sha256', $token);
        $user = $this->em->getRepository(User::class)->findOneBy(['verificationHash' => $hash]);
        if (!$user || $user->isVerified || !$user->verificationExpiresAt || $user->verificationExpiresAt <= new \DateTimeImmutable()) { return false; }
        $passwordHash = $this->hasher->hashPassword($user, $password);
        // Conditional UPDATE consumes the token atomically, including simultaneous requests on PostgreSQL.
        $count = $this->em->createQueryBuilder()->update(User::class, 'u')
            ->set('u.password', ':password')->set('u.isVerified', ':verified')
            ->set('u.verificationHash', ':empty')->set('u.verificationExpiresAt', ':empty')
            ->where('u.id = :id AND u.verificationHash = :hash AND u.isVerified = :pending AND u.verificationExpiresAt > :now')
            ->setParameter('password', $passwordHash)->setParameter('verified', true)->setParameter('empty', null)
            ->setParameter('id', $user->id)->setParameter('hash', $hash)->setParameter('pending', false)
            ->setParameter('now', new \DateTimeImmutable())->getQuery()->execute();
        $this->em->clear();
        return $count === 1;
    }
}
