<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{AgentRun, Job, JobMatch, Profile, User};
use App\Service\UserImpersonator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class ImpersonationTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private User $member;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertStringContainsString('worklook_test', $this->em->getConnection()->getParams()['path']);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schema = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $this->admin = $this->account(User::ADMIN_EMAIL, User::ADMIN_KEY);
        $this->member = $this->account('member@test.local');
    }

    private function account(string $email, ?string $key = null): User
    {
        $user = new User(); $user->email = $email; $user->adminKey = $key; $user->isVerified = true;
        $user->password = password_hash('private-test-password-123', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->em->persist($user); $this->em->flush(); return $user;
    }

    private function enter(): \Symfony\Component\DomCrawler\Crawler
    {
        $this->client->loginUser($this->admin);
        $page = $this->client->request('GET', '/admin/users/'.$this->member->id);
        $this->client->submit($page->filter('form[action$="/impersonate"]')->form());
        self::assertResponseRedirects('/', 303);
        return $this->client->followRedirect();
    }

    public function testEntryAndReturnPreserveAccountDataAndRecordOriginalActor(): void
    {
        $db = $this->em->getConnection();
        $before = $db->fetchAllAssociative('SELECT * FROM app_user ORDER BY id');
        $page = $this->enter();
        self::assertSelectorTextContains('.impersonation-banner', $this->member->email);
        self::assertSelectorNotExists('a.nav-link[href="/admin"]');
        self::assertSelectorTextContains('.account-info', $this->member->email);
        $view = $db->fetchAssociative("SELECT actor_id, context FROM activity_event WHERE kind = 'page_view' ORDER BY recorded_at DESC LIMIT 1");
        self::assertSame((string) $this->member->id, $view['actor_id']);
        $context = json_decode($view['context'], true);
        self::assertSame($this->admin->id, $context['impersonator_user_id']);
        self::assertSame($this->member->id, $context['effective_user_id']);
        $this->client->submit($page->filter('form[action="/account/impersonation/exit"]')->form());
        self::assertResponseRedirects('/admin/users/'.$this->member->id, 303);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.impersonation-banner');
        self::assertSelectorTextContains('.account-info', User::ADMIN_EMAIL);
        self::assertSame($before, $db->fetchAllAssociative('SELECT * FROM app_user ORDER BY id'));
        $audit = $db->fetchAllAssociative("SELECT actor_id, context FROM activity_event WHERE kind = 'admin_action'");
        self::assertCount(2, $audit);
        $actions = [];
        foreach ($audit as $event) {
            self::assertSame((string) $this->admin->id, $event['actor_id']);
            $context = json_decode($event['context'], true); $actions[] = $context['action'];
            self::assertSame($this->member->id, $context['target_user_id']);
        }
        self::assertEqualsCanonicalizing(['impersonation_started', 'impersonation_ended'], $actions);
        $stored = json_encode($db->fetchAllAssociative('SELECT * FROM activity_event'));
        self::assertStringNotContainsString('private-test-password', $stored);
        self::assertStringNotContainsString($this->member->password, $stored);
        self::assertStringNotContainsString('_token', $stored);
    }

    public function testEntryExitRequirePostCsrfAndAdministrator(): void
    {
        $path = '/admin/users/'.$this->member->id.'/impersonate';
        $this->client->request('POST', $path); self::assertResponseRedirects('/login');
        $this->client->loginUser($this->member);
        $this->client->request('POST', $path); self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/account/impersonation/exit'); self::assertResponseStatusCodeSame(403);
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $path); self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', $path, ['_token' => 'invalid']); self::assertResponseStatusCodeSame(403);
        $this->enter();
        $this->client->request('GET', '/account/impersonation/exit'); self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/account/impersonation/exit', ['_token' => 'invalid']); self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/'); self::assertSelectorExists('.impersonation-banner');
        $this->client->request('POST', $path); self::assertResponseStatusCodeSame(403);
    }

    public function testRawSwitchParametersCannotChangeIdentity(): void
    {
        $this->client->loginUser($this->admin);
        foreach (['_switch_user', '_worklook_impersonate'] as $parameter) {
            $this->client->request('GET', '/?'.http_build_query([$parameter => $this->member->email]), server: ['HTTP_'.strtoupper($parameter) => $this->member->email]);
            self::assertSelectorNotExists('.impersonation-banner'); self::assertSelectorTextContains('.account-info', User::ADMIN_EMAIL);
            $this->client->request('POST', '/', [$parameter => $this->member->email]);
            $this->client->request('GET', '/'); self::assertSelectorNotExists('.impersonation-banner');
        }
        $this->enter();
        $this->client->request('GET', '/?_switch_user=_exit'); self::assertSelectorExists('.impersonation-banner');
    }

    public function testSelfUnverifiedAndNestedTargetsAreRejected(): void
    {
        $unverified = $this->account('unverified@test.local'); $unverified->isVerified = false; $this->em->flush();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/'.$unverified->id); self::assertSelectorNotExists('form[action$="/impersonate"]');
        $this->client->request('GET', '/admin/users/'.$this->admin->id); self::assertSelectorNotExists('form[action$="/impersonate"]');
        $service = self::getContainer()->get(UserImpersonator::class);
        foreach ([$this->admin, $unverified] as $target) {
            try { $service->enter(Request::create('/', 'POST'), $target); self::fail('Target must be rejected.'); }
            catch (AccessDeniedException) { self::assertTrue(true); }
        }
        $this->enter();
        try { self::getContainer()->get(UserImpersonator::class)->enter(Request::create('/', 'POST'), $this->member); self::fail('Nested entry must be rejected.'); }
        catch (AccessDeniedException) { self::assertTrue(true); }
    }

    public function testSwitchedUserCannotReadAdminOrAnotherUsersPrivateRecords(): void
    {
        $other = $this->account('other@test.local'); $profile = new Profile($other);
        $run = new AgentRun($other); $run->profileSnapshot = $profile->snapshot();
        $job = new Job(); $job->source = 'solidjobs'; $job->externalId = 'private'; $job->title = 'Private job';
        $match = new JobMatch($run, $job);
        foreach ([$run, $job, $match] as $item) { $this->em->persist($item); } $this->em->flush();
        $this->enter();
        $this->client->request('GET', '/admin'); self::assertResponseStatusCodeSame(403);
        foreach (['/runs/'.$run->id, '/matches/'.$match->id, '/matches/'.$match->id.'/cv.pdf'] as $path) {
            $this->client->request('GET', $path); self::assertResponseStatusCodeSame(404);
        }
    }

    public function testAuditFailureDoesNotSwitchEitherIdentity(): void
    {
        $this->client->loginUser($this->admin); $this->client->disableReboot();
        $page = $this->client->request('GET', '/admin/users/'.$this->member->id);
        $form = $page->filter('form[action$="/impersonate"]')->form();
        $db = $this->em->getConnection(); $db->executeStatement('ALTER TABLE activity_event RENAME TO activity_event_saved');
        $this->client->catchExceptions(false);
        try {
            try { $this->client->submit($form); self::fail('Audit must be required.'); }
            catch (\RuntimeException $error) { self::assertSame('Administrator action audit unavailable.', $error->getMessage()); }
            self::assertSame($this->admin->id, self::getContainer()->get(TokenStorageInterface::class)->getToken()->getUser()->id);
        } finally { $db->executeStatement('ALTER TABLE activity_event_saved RENAME TO activity_event'); }
        $page = $this->enter();
        $form = $page->filter('form[action="/account/impersonation/exit"]')->form();
        $db->executeStatement('ALTER TABLE activity_event RENAME TO activity_event_saved');
        try {
            try { $this->client->submit($form); self::fail('Exit audit must be required.'); }
            catch (\RuntimeException $error) { self::assertSame('Administrator action audit unavailable.', $error->getMessage()); }
            self::assertSame($this->member->id, self::getContainer()->get(TokenStorageInterface::class)->getToken()->getUser()->id);
        } finally { $db->executeStatement('ALTER TABLE activity_event_saved RENAME TO activity_event'); }
    }

    public function testLogoutEndsSwitchedSessionAndKeepsAdministratorAttribution(): void
    {
        $page = $this->enter(); $this->client->submit($page->filter('form[action="/logout"]')->form());
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/admin'); self::assertResponseRedirects('/login');
        $event = $this->em->getConnection()->fetchAssociative("SELECT actor_id, context FROM activity_event WHERE kind = 'logout'");
        self::assertSame((string) $this->member->id, $event['actor_id']);
        self::assertSame($this->admin->id, json_decode($event['context'], true)['impersonator_user_id']);
    }

    public function testRevokedOriginalAdministratorCannotBeRestored(): void
    {
        $this->enter();
        $this->em->getConnection()->executeStatement('UPDATE app_user SET admin_key = NULL WHERE id = ?', [$this->admin->id]);
        $this->client->request('GET', '/'); self::assertResponseRedirects('/login');
        $this->client->request('POST', '/account/impersonation/exit', ['_token' => 'unused']); self::assertResponseRedirects('/login');
    }

    public function testAdminProfileEditorIsRemovedAndOwnProfileStillWorks(): void
    {
        $profile = new Profile($this->member); $profile->displayName = 'Original name'; $this->em->persist($profile); $this->em->flush();
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/users/'.$this->member->id);
        self::assertSelectorNotExists('form[name="profile"]');
        $this->client->request('POST', '/admin/users/'.$this->member->id, ['profile' => ['displayName' => 'Forged edit']]); self::assertResponseStatusCodeSame(405);
        self::assertSame('Original name', $this->em->getConnection()->fetchOne('SELECT display_name FROM profile WHERE user_id = ?', [$this->member->id]));
        $this->client->loginUser($this->member); $this->client->request('GET', '/profile'); self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[name="profile"]');
    }
}
