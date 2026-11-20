<?php
namespace App\Tests;

use App\Entity\{AgentRun, User};
use App\Service\EmailRegistration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $em = $this->em();
        self::assertStringContainsString('worklook_test', $em->getConnection()->getParams()['path']);
        $em->getConfiguration()->setUseSchemaEditorApi(true);
        $tool = new SchemaTool($em); $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata); $tool->createSchema($metadata);
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
    private function em(): EntityManagerInterface { return self::getContainer()->get(EntityManagerInterface::class); }
    private function requestEmail(string $address = 'new@test.local'): ?Email
    {
        $page = $this->client->request('GET', '/register');
        $this->client->submit($page->filterXPath('//form[input[@name="email"]]')->form(['email' => $address]));
        self::assertResponseIsSuccessful();
        return self::getMailerMessage();
    }
    private function token(Email $email): string
    {
        self::assertMatchesRegularExpression('~https://worklook.test/verify-email#token=[a-f0-9]{64}~', $email->getTextBody());
        preg_match('/#token=([a-f0-9]{64})/', $email->getTextBody(), $matches);
        return $matches[1];
    }
    private function confirm(string $token, string $password = 'a long unique test password'): void
    {
        $page = $this->client->request('GET', '/verify-email');
        $this->client->submit($page->filterXPath('//form[input[@name="verification_code"]]')->form([
            'verification_code' => $token, 'password' => $password, 'password_confirm' => $password,
        ]));
    }

    public function testEmailOwnershipIsRequiredBeforeChoosingPasswordAndLogin(): void
    {
        $mail = $this->requestEmail('NEW@test.local'); self::assertEmailCount(1);
        $token = $this->token($mail);
        $pending = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@test.local']);
        self::assertFalse($pending->isVerified); self::assertFalse($pending->liveAiEnabled);
        self::assertSame(hash('sha256', $token), $pending->verificationHash);
        self::assertNotSame($token, $pending->verificationHash);
        $this->client->request('GET', '/verify-email?token='.$token); // GET must not consume or activate anything.
        self::assertFalse($this->em()->getRepository(User::class)->find($pending->id)->isVerified);
        $this->confirm($token); self::assertResponseRedirects('/login');
        $user = $this->em()->getRepository(User::class)->find($pending->id);
        self::assertTrue($user->isVerified); self::assertNull($user->verificationHash); self::assertNull($user->verificationExpiresAt);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'a long unique test password'));
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->selectButton('Увійти')->form(['_username' => 'new@test.local', '_password' => 'a long unique test password']));
        self::assertResponseRedirects('/');
    }
    public function testTokenIsSingleUseAndUnknownOrExpiredTokensFail(): void
    {
        $token = $this->token($this->requestEmail());
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@test.local']);
        $user->verificationExpiresAt = new \DateTimeImmutable('-1 minute'); $this->em()->flush();
        $this->confirm($token); self::assertResponseIsSuccessful(); self::assertSelectorExists('[role="alert"]');
        $this->confirm(str_repeat('a', 64)); self::assertSelectorExists('[role="alert"]');
        $user = $this->em()->getRepository(User::class)->find($user->id); $user->verificationExpiresAt = new \DateTimeImmutable('+1 hour'); $this->em()->flush();
        $this->confirm($token); self::assertResponseRedirects('/login');
        $this->confirm($token, 'attacker replacement password'); self::assertSelectorExists('[role="alert"]');
        $user = $this->em()->getRepository(User::class)->find($user->id);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'a long unique test password'));
    }
    public function testCsrfAndPasswordValidation(): void
    {
        $this->client->request('POST', '/register', ['email' => 'new@test.local', '_token' => 'bad']); self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/verify-email', ['_token' => 'bad']); self::assertResponseStatusCodeSame(403);
        $token = $this->token($this->requestEmail());
        $this->confirm($token, 'short'); self::assertSelectorExists('[role="alert"]');
        $page = $this->client->request('GET', '/verify-email');
        $this->client->submit($page->filterXPath('//form[input[@name="verification_code"]]')->form(['verification_code' => $token, 'password' => 'a long unique test password', 'password_confirm' => 'different long password']));
        self::assertSelectorExists('[role="alert"]');
        self::assertFalse($this->em()->getRepository(User::class)->findOneBy(['email' => 'new@test.local'])->isVerified);
    }
    public function testExistingAccountIsNotChangedOrDisclosedAndHostCannotPoisonEmailLink(): void
    {
        $mail = $this->requestEmail(); $token = $this->token($mail); $this->confirm($token);
        $before = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@test.local'])->password;
        self::assertNull($this->requestEmail()); self::assertEmailCount(0);
        self::assertSelectorTextContains('[role="status"]', 'Якщо для цієї адреси');
        self::assertSame($before, $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@test.local'])->password);
        $this->client->setServerParameter('HTTP_HOST', 'attacker.test');
        $mail = $this->requestEmail('second@test.local'); self::assertStringNotContainsString('attacker.test', $mail->getTextBody()); $this->token($mail);
    }
    public function testMailIsLocalizedAndRequestIsThrottled(): void
    {
        $this->client->getCookieJar()->set(new Cookie('worklook_locale', 'pl'));
        $mail = $this->requestEmail(); self::assertSame('Potwierdź email — WorkLook', $mail->getSubject());
        $this->requestEmail(); $this->requestEmail(); self::assertEmailCount(1);
        self::assertNull($this->requestEmail()); self::assertEmailCount(0);
        self::assertSelectorTextContains('[role="status"]', 'Jeśli dla tego adresu');
    }
    public function testPendingAccountCannotLoginEvenWithCorrectInternalPassword(): void
    {
        $this->requestEmail(); $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'new@test.local']);
        $user->password = self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'a known internal password'); $this->em()->flush();
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->selectButton('Увійти')->form(['_username' => $user->email, '_password' => 'a known internal password']));
        self::assertResponseRedirects('/login'); $this->client->request('GET', '/'); self::assertResponseRedirects('/login');
    }
    public function testPublicAccountCannotSubmitLiveAiRun(): void
    {
        $user = new User(); $user->email = 'public@test.local'; $user->isVerified = true; $user->password = 'unusable'; $this->em()->persist($user); $this->em()->flush();
        $this->client->loginUser($user); $page = $this->client->request('GET', '/');
        $this->client->submit($page->filter('form[name="run"]')->form(['run[mode]' => 'live', 'run[sources]' => ['solidjobs'], 'run[minimumScore]' => 65]));
        self::assertResponseRedirects('/'); self::assertSame(0, $this->em()->getRepository(AgentRun::class)->count([]));
    }
    public function testSmtpFailureInvalidatesUnsentTokenWithoutLeakingCredentials(): void
    {
        $mailer = $this->createMock(MailerInterface::class); $mailer->method('send')->willThrowException(new \RuntimeException('smtp://SECRET_PASSWORD@host'));
        self::getContainer()->set(MailerInterface::class, $mailer);
        self::getContainer()->get(EmailRegistration::class)->request('failed@test.local', 'en');
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'failed@test.local']);
        self::assertFalse($user->isVerified); self::assertNull($user->verificationHash); self::assertNull($user->verificationExpiresAt);
    }
}
