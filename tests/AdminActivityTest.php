<?php
declare(strict_types=1);
namespace App\Tests;

use App\Command\{ProvisionAdminCommand, PruneActivityCommand};
use App\Entity\{AgentRun, Job, JobMatch, Profile, User};
use App\Service\{ActivityQuery, ActivityRecorder, IpLocation};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminActivityTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $user;
    protected function setUp(): void
    {
        $this->client = self::createClient(); $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertStringContainsString('worklook_test', $this->em->getConnection()->getParams()['path']);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schema = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $this->admin = $this->account(User::ADMIN_EMAIL, User::ADMIN_KEY); $this->user = $this->account('member@test.local');
    }
    private function account(string $email, ?string $key = null): User
    {
        $user = new User(); $user->email = $email; $user->adminKey = $key; $user->isVerified = true;
        $user->password = password_hash('valid-test-password-123', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->em->persist($user); $this->em->flush(); return $user;
    }
    private function eventCount(string $kind): int { return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM activity_event WHERE kind = ?', [$kind]); }

    public function testOnlyFixedProvisionedIdentityCanBeAdministrator(): void
    {
        self::assertTrue($this->admin->isAdministrator());
        $forged = new User(); $forged->email = User::ADMIN_EMAIL; $forged->isVerified = true;
        self::assertNotContains('ROLE_ADMIN', $forged->getRoles());
        $forged->adminKey = User::ADMIN_KEY; $forged->email = 'other@test.local';
        self::assertNotContains('ROLE_ADMIN', $forged->getRoles());
        $forged->email = User::ADMIN_EMAIL; $forged->isVerified = false;
        self::assertNotContains('ROLE_ADMIN', $forged->getRoles());
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->account('duplicate@test.local', User::ADMIN_KEY);
    }
    public function testAdminRoutesDenyGuestAndOrdinaryUser(): void
    {
        $this->client->request('GET', '/admin'); self::assertResponseRedirects('/login');
        $this->client->loginUser($this->user);
        foreach (['/admin', '/admin/users', '/admin/users/'.$this->admin->id] as $path) {
            $this->client->request('GET', $path); self::assertResponseStatusCodeSame(403);
        }
        $this->client->request('POST', '/admin/users/'.$this->user->id.'/ai', ['enabled' => '0']); self::assertResponseStatusCodeSame(403);
    }
    public function testAdminScreensRenderInAllLanguagesAndReadOtherUsersRecords(): void
    {
        $profile = new Profile($this->user); $profile->displayName = 'Member'; $profile->factsText = 'Confirmed PHP skill.';
        $run = new AgentRun($this->user); $run->profileSnapshot = $profile->snapshot();
        $job = new Job(); $job->source = 'solidjobs'; $job->externalId = 'admin-fixture'; $job->title = 'PHP engineer';
        $match = new JobMatch($run, $job);
        foreach ([$profile, $run, $job, $match] as $entity) { $this->em->persist($entity); } $this->em->flush();
        $this->client->loginUser($this->admin);
        foreach (['uk' => 'Статистика та журнал', 'pl' => 'Statystyki i dziennik', 'en' => 'Statistics and activity'] as $locale => $heading) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('worklook_locale', $locale));
            $this->client->request('GET', '/admin'); self::assertResponseIsSuccessful(); self::assertSelectorTextContains('h1', $heading);
            self::assertStringNotContainsString('admin.kind.', $this->client->getResponse()->getContent());
        }
        foreach (['/admin/users', '/admin/users/'.$this->user->id, '/runs/'.$run->id, '/matches/'.$match->id] as $path) {
            $this->client->request('GET', $path); self::assertResponseIsSuccessful();
        }
        self::assertSame(2, $this->eventCount('page_view')); // Only the run and match, not admin pages.
    }
    public function testVisitLogDoesNotStoreQuerySecretsOrTrustSpoofedForwardingHeaders(): void
    {
        $this->client->request('GET', '/login?token=private-query-secret', server: ['REMOTE_ADDR' => '8.8.8.8',
            'HTTP_X_FORWARDED_FOR' => '1.1.1.1', 'HTTP_REFERER' => 'https://example.com/path?secret=private-referrer-secret',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/123.0']);
        self::assertResponseIsSuccessful();
        $event = $this->em->getConnection()->fetchAssociative('SELECT * FROM activity_event WHERE kind = ?', ['page_view']);
        self::assertSame('8.8.8.8', $event['ip']); self::assertSame('/login', $event['path']); self::assertSame('example.com', $event['referrer_host']);
        $stored = json_encode($event); self::assertStringNotContainsString('private-query-secret', $stored); self::assertStringNotContainsString('private-referrer-secret', $stored);
        $this->client->request('GET', '/unknown-secret-path'); self::assertResponseStatusCodeSame(404);
        self::assertSame('[unmatched]', $this->em->getConnection()->fetchOne("SELECT path FROM activity_event WHERE status_code = 404"));
        self::assertNull((new IpLocation('/missing.mmdb'))->locate('127.0.0.1')['country']);
        self::assertNull((new IpLocation('/missing.mmdb'))->locate('invalid-ip')['country']);
    }
    public function testLoginSuccessFailureAndLogoutAreRecordedWithoutPasswordsOrTokens(): void
    {
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->filter('form[action="/login"]')->form(['_username' => $this->user->email, '_password' => 'wrong-password-sensitive']));
        self::assertSame(1, $this->eventCount('login_failure'));
        $page = $this->client->request('GET', '/login');
        $this->client->submit($page->filter('form[action="/login"]')->form(['_username' => $this->user->email, '_password' => 'valid-test-password-123']));
        self::assertResponseRedirects('/'); self::assertSame(1, $this->eventCount('login_success'));
        $page = $this->client->followRedirect(); $this->client->submit($page->filter('form[action="/logout"]')->form());
        self::assertSame(1, $this->eventCount('logout'));
        $events = json_encode($this->em->getConnection()->fetchAllAssociative('SELECT * FROM activity_event'));
        self::assertStringNotContainsString('valid-test-password', $events); self::assertStringNotContainsString('wrong-password-sensitive', $events);
        self::assertStringNotContainsString('_csrf_token', $events);
    }
    public function testGatewaysAndPollingAreExcludedFromWorkLookStatistics(): void
    {
        $this->client->request('POST', '/telegram/webhook', content: '{"message":{"text":"private-bot-message"}}'); self::assertResponseStatusCodeSame(503);
        $this->client->request('POST', '/translator/telegram/webhook'); self::assertResponseStatusCodeSame(503);
        $this->client->request('GET', '/healthz'); self::assertResponseIsSuccessful();
        $this->client->request('GET', '/login', server: ['HTTP_TURBO_FRAME' => 'run-status']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM activity_event'));
    }
    public function testAdminMutationRequiresCsrfAndKeepsAllowanceAndAudit(): void
    {
        $id = $this->user->id; $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/'.$id.'/ai', ['enabled' => '0', '_token' => 'invalid']); self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->eventCount('admin_action'));
        $page = $this->client->request('GET', '/admin/users/'.$id);
        $this->client->submit($page->filter('form[action="/admin/users/'.$id.'/ai"]')->form()); self::assertResponseRedirects('/admin/users/'.$id, 303);
        $row = $this->em->getConnection()->fetchAssociative('SELECT live_ai_enabled, free_search_run_id FROM app_user WHERE id = ?', [$id]);
        self::assertFalse((bool) $row['live_ai_enabled']); self::assertNull($row['free_search_run_id']);
        self::assertSame(1, $this->eventCount('admin_action'));
        $audit = $this->em->getConnection()->fetchAssociative("SELECT actor_id, context FROM activity_event WHERE kind = 'admin_action'");
        self::assertSame((string) $this->admin->id, $audit['actor_id']); self::assertSame($id, json_decode($audit['context'], true)['target_user_id']);
    }
    public function testProvisionIsIdempotentAndRegistrationCannotClaimReservedEmail(): void
    {
        $hash = $this->admin->password;
        $tester = new CommandTester(new ProvisionAdminCommand($this->em, self::getContainer()->get(UserPasswordHasherInterface::class)));
        self::assertSame(0, $tester->execute([])); self::assertSame($hash, $this->admin->password);
        self::getContainer()->get(\App\Service\EmailRegistration::class)->request(User::ADMIN_EMAIL, 'en');
        self::assertNull($this->admin->verificationHash);
        self::assertStringNotContainsString($hash, $tester->getDisplay());
    }
    public function testAuditFailureRollsBackAdministratorPermissionChange(): void
    {
        $id = $this->user->id; $db = $this->em->getConnection();
        $this->client->loginUser($this->admin); $this->client->disableReboot();
        $page = $this->client->request('GET', '/admin/users/'.$id);
        $form = $page->filter('form[action="/admin/users/'.$id.'/ai"]')->form();
        $db->executeStatement('DROP TABLE activity_event');
        $this->client->catchExceptions(false);
        try { $this->client->submit($form); self::fail('Missing audit storage must reject the mutation.'); }
        catch (\RuntimeException $error) { self::assertSame('Administrator action audit unavailable.', $error->getMessage()); }
        self::assertTrue((bool) $db->fetchOne('SELECT live_ai_enabled FROM app_user WHERE id = ?', [$id]));
    }
    public function testVisitLoggingFailureDoesNotBreakPublicPages(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE activity_event');
        $this->client->request('GET', '/login'); self::assertResponseIsSuccessful();
    }
    public function testProvisionCreatesOnlyTheFixedAccountFromPrivatePasswordInput(): void
    {
        $this->em->remove($this->admin); $this->em->flush();
        $tester = new CommandTester(new ProvisionAdminCommand($this->em, self::getContainer()->get(UserPasswordHasherInterface::class)));
        self::assertSame(2, $tester->execute([]));
        $password = 'private-test-provision-password'; putenv('WORKLOOK_ADMIN_TEST_PASSWORD='.$password);
        try { self::assertSame(0, $tester->execute(['--password-env' => 'WORKLOOK_ADMIN_TEST_PASSWORD'])); }
        finally { putenv('WORKLOOK_ADMIN_TEST_PASSWORD'); }
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => User::ADMIN_EMAIL]);
        self::assertTrue($user->isAdministrator()); self::assertTrue(password_verify($password, $user->password));
        self::assertStringNotContainsString($password, $tester->getDisplay());
    }
    public function testFilteringAndRetentionKeepLongerAdministratorAudit(): void
    {
        $recorder = self::getContainer()->get(ActivityRecorder::class); $db = $this->em->getConnection();
        foreach (['page_view', 'admin_action'] as $kind) { self::assertTrue($recorder->record(Request::create('/login'), $kind, 200, $this->admin)); }
        $db->executeStatement("UPDATE activity_event SET recorded_at = ?", [gmdate('Y-m-d H:i:s', time() - 100 * 86400)]);
        $tester = new CommandTester(new PruneActivityCommand($db)); self::assertSame(0, $tester->execute(['--dry-run' => true]));
        self::assertSame(2, (int) $db->fetchOne('SELECT COUNT(*) FROM activity_event'));
        self::assertSame(0, $tester->execute([])); self::assertSame(0, $this->eventCount('page_view')); self::assertSame(1, $this->eventCount('admin_action'));
        self::assertTrue($recorder->record(Request::create('/login', 'POST', ['_username' => $this->user->email]), 'login_failure', 302, $this->user));
        $query = new ActivityQuery($db); $filters = $query->filters(Request::create('/admin?kind=login_failure&q=member'));
        $report = $query->report($filters); self::assertSame(1, $report['count']);
        self::assertSame('login_failure', $report['events'][0]['kind']);
        self::assertSame(1, $query->report($query->filters(Request::create('/admin?errors=1')))['count']);
        $filters = $query->filters(Request::create('/admin?days=999&kind=bogus&country=XYZ&page=-1'));
        self::assertSame(7, $filters['days']); self::assertSame('', $filters['kind']); self::assertSame('', $filters['country']); self::assertSame(1, $filters['page']);
    }
}
