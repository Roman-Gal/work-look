<?php
namespace App\Tests;

use App\Agent\{AgentRunner, OpenAiClient, ToolExecutor};
use App\Entity\{AgentRun, Job, JobMatch, Profile, User};
use App\Service\{CanonicalCv, SearchAllowance, SearchLauncher};
use App\Source\{JobCatalog, RssJobsSource, SolidJobsSource, SourceHttpClient};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\{LockFactory, Store\InMemoryStore};

final class ApplicationTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private User $owner;
    private User $other;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertStringContainsString('worklook_test', $this->em->getConnection()->getParams()['path']);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schema = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata);
        $this->owner = $this->user('owner@test.local'); $this->other = $this->user('other@test.local');
        $profile = new Profile($this->owner); $profile->displayName = 'Тестовий Кандидат'; $profile->factsText = "PHP: підтверджена навичка.\nАнглійська B2.";
        $profile->headline = 'PHP Developer'; $this->em->persist($profile); $this->em->flush();
    }
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private function user(string $email): User
    {
        $user = new User(); $user->isVerified = true; $user->liveAiEnabled = true; $user->email = $email; $user->password = password_hash('valid-local-test-password', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->em->persist($user); $this->em->flush(); return $user;
    }
    private function createRun(string $mode = 'live'): AgentRun
    {
        $run = new AgentRun($this->owner); $run->mode = $mode; $run->sources = ['solidjobs'];
        $run->profileSnapshot = $this->em->getRepository(Profile::class)->findOneBy(['user' => $this->owner])->snapshot();
        $this->em->persist($run); $this->em->flush();
        if ($mode === 'live') { self::assertTrue((new SearchAllowance($this->em))->reserve($this->owner, $run)); $this->em->refresh($this->owner); }
        return $run;
    }
    private function runner(?MockHttpClient $modelHttp = null, string $apiKey = 'test-only-key'): AgentRunner
    {
        $sourceHttp = new SourceHttpClient(new MockHttpClient(new MockResponse(json_encode(SourceTest::solidPayload()))), new ArrayAdapter());
        $catalog = new JobCatalog(new SolidJobsSource($sourceHttp), new RssJobsSource($sourceHttp), $this->em);
        return new AgentRunner(new OpenAiClient($modelHttp ?? new MockHttpClient(), $apiKey, 'test-model'),
            new ToolExecutor($catalog, $this->em, new CanonicalCv()), $this->em, new LockFactory(new InMemoryStore()), new NullLogger(), new SearchAllowance($this->em));
    }
    public function testUnauthenticatedVisitorsCannotReadDashboardOrFiles(): void
    {
        $this->client->request('GET', '/'); self::assertResponseRedirects('/login');
        $this->client->request('GET', '/matches/1/cv.pdf'); self::assertResponseRedirects('/login');
        $this->client->request('GET', '/login'); self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY'); self::assertSelectorExists('input[name="_csrf_token"]');
    }
    public function testWorkerChecksLiveAiPermissionAgainBeforeCallingModel(): void
    {
        $run = $this->createRun('live'); $this->owner->liveAiEnabled = false; $this->em->flush();
        $this->runner()->run($run);
        self::assertSame('failed', $run->status); self::assertSame(0, $run->modelCalls);
    }
    public function testLoginValidatesPasswordAndCsrf(): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Увійти')->form(['_username' => 'owner@test.local', '_password' => 'valid-local-test-password']));
        self::assertResponseRedirects('/'); $this->client->followRedirect(); self::assertSelectorTextContains('h1', 'Ваша наступна можливість');
    }
    public function testCrossUserRunMatchAndPdfAreNotAccessible(): void
    {
        $run = $this->createRun(); $this->fixtureMatch($run);
        $match = $this->em->getRepository(JobMatch::class)->findOneBy(['run' => $run]);
        $id = $match->id; $runId = $run->id; $this->client->loginUser($this->other);
        foreach (['/runs/'.$runId, '/matches/'.$id, '/matches/'.$id.'/cv.pdf'] as $url) {
            $this->client->request('GET', $url); self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('POST', '/matches/'.$id.'/status', ['status' => 'Applied', '_token' => 'invalid']); self::assertResponseStatusCodeSame(404);
    }
    public function testStatusCannotChangeWithoutCsrf(): void
    {
        $run = $this->createRun(); $this->fixtureMatch($run);
        $id = $this->em->getRepository(JobMatch::class)->findOneBy(['run' => $run])->id;
        $this->client->loginUser($this->owner);
        $this->client->request('POST', '/matches/'.$id.'/status', ['status' => 'Applied', '_token' => 'invalid']); self::assertResponseStatusCodeSame(403);
    }
    public function testProfileAcceptsEmptyOptionalFieldsAndEscapesHtml(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Зберегти профіль →')->form([
            'profile[displayName]' => 'Кандидат', 'profile[headline]' => '', 'profile[contactLine]' => '',
            'profile[searchTerms]' => '', 'profile[preferences]' => '', 'profile[factsText]' => 'PHP <script>alert(1)</script>',
        ]));
        self::assertResponseRedirects('/profile'); $this->client->followRedirect();
        self::assertSelectorExists('textarea'); self::assertStringNotContainsString('<script>alert(1)</script>', $this->client->getResponse()->getContent());
    }
    public function testLanguageSelectionPersistsFromLoginToPrivateForms(): void
    {
        $crawler = $this->client->request('GET', '/login');
        self::assertSelectorExists('html[lang="uk"]');
        $this->client->submit($crawler->filter('form[action="/language/pl"]')->form());
        self::assertResponseRedirects('/login', 303);
        $cookie = $this->client->getResponse()->headers->getCookies()[0];
        self::assertSame('worklook_locale', $cookie->getName());
        self::assertSame('pl', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        $this->client->followRedirect();
        self::assertSelectorExists('html[lang="pl"]');
        self::assertSelectorTextContains('h2', 'Zaloguj się do panelu');
        $this->client->loginUser($this->owner);
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Twoja kolejna szansa');
        self::assertSelectorExists('textarea[placeholder^="Na przykład:"]');
        $crawler = $this->client->request('GET', '/profile');
        self::assertSelectorTextContains('label[for="profile_displayName"]', 'Imię i nazwisko');
        self::assertSelectorTextContains('textarea#profile_factsText', 'Англійська B2.');
        $this->client->submit($crawler->filter('form[action="/language/en"]')->form());
        self::assertResponseRedirects('/profile', 303); $this->client->followRedirect();
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('h1', 'Your experience. Your terms');
        self::assertSelectorTextContains('textarea#profile_factsText', 'Англійська B2.');
    }
    public function testLanguageSwitchRequiresCsrfAndOnlyRedirectsToKnownLocalPages(): void
    {
        $this->client->loginUser($this->owner);
        $this->client->request('POST', '/language/en', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/language/en"]')->form();
        $this->client->submit($form, ['return_to' => '//external.example/steal']);
        self::assertResponseRedirects('/', 303);
        $this->client->request('GET', '/language/en'); self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/language/de'); self::assertResponseStatusCodeSame(404);
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('worklook_locale', 'unsupported', domain: 'localhost'));
        $this->client->request('GET', '/'); self::assertSelectorExists('html[lang="uk"]');
    }
    public function testLocalizedRunAndMatchPreserveFactsAcrossLanguages(): void
    {
        $run = $this->createRun(); $this->fixtureMatch($run);
        $match = $this->em->getRepository(JobMatch::class)->findOneBy(['run' => $run]);
        $facts = $run->profileSnapshot['facts'];
        $this->client->loginUser($this->owner);
        foreach (['uk' => ['Завершено', 'Відповідність профілю'], 'pl' => ['Zakończono', 'Dopasowanie do profilu'], 'en' => ['Completed', 'Profile fit']] as $locale => [$status, $heading]) {
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('worklook_locale', $locale));
            $this->client->request('GET', '/runs/'.$run->id);
            self::assertResponseIsSuccessful(); self::assertSelectorTextContains('.badge', $status);

            $this->client->request('GET', '/matches/'.$match->id);
            self::assertResponseIsSuccessful(); self::assertSelectorTextContains('h2', $heading);
            self::assertSelectorTextContains('details', 'Англійська B2.');
        }
        self::assertSame($facts, $run->profileSnapshot['facts']);
    }
    private function fixtureMatch(AgentRun $run): JobMatch
    {
        $job = new Job(); $job->source = 'solidjobs'; $job->externalId = 'fixture-php'; $job->title = 'PHP Developer';
        $job->company = 'Fixture company'; $job->description = 'PHP and English B2.'; $job->url = 'https://solid.jobs/offer/fixture';
        $job->completeness = 'full'; $job->workMode = 'remote'; $job->locations = ['Warszawa']; $this->em->persist($job);
        $match = new JobMatch($run, $job); $match->score = 75; $match->selectedFactIds = array_column($run->profileSnapshot['facts'], 'id');
        $run->status = 'completed'; $run->finishedAt = new \DateTimeImmutable(); $this->em->persist($match); $this->em->flush(); return $match;
    }
    public function testPdfContainsOnlySnapshotFacts(): void
    {
        $run = $this->createRun(); $match = $this->fixtureMatch($run);
        self::assertCount(2, $match->selectedFactIds);
        $this->client->loginUser($this->owner); $this->client->request('GET', '/matches/'.$match->id.'/cv.pdf');
        self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF-', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Zakon-Regular', $this->client->getResponse()->getContent());
        self::assertStringContainsString('Zakon-Bold', $this->client->getResponse()->getContent());
    }
    public function testCancelledQueuedRunDoesNotCallModel(): void
    {
        $run = $this->createRun('live'); $run->cancelRequested = true; $this->em->flush(); $this->runner()->run($run);
        self::assertSame('cancelled', $run->status); self::assertSame(0, $run->modelCalls);
        self::assertSame(1, (new SearchAllowance($this->em))->remaining($this->owner));
    }
    public function testOnlyOneSearchCanBeQueuedAndPaymentButtonIsAPlaceholder(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(OpenAiClient::class, new OpenAiClient(new MockHttpClient(), 'test-key', 'test-model'));
        $this->client->loginUser($this->owner);
        $page = $this->client->request('GET', '/');
        self::assertSelectorNotExists('select[name="run[mode]"]');
        self::assertSelectorExists('button[data-testid="future-payment"][disabled]');
        self::assertSelectorTextContains('[data-testid="search-allowance"]', '1');
        $form = $page->filter('form[name="run"]')->form(); $this->client->submit($form);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(1, $this->em->getRepository(AgentRun::class)->count([]));
        self::assertSame(0, (new SearchAllowance($this->em))->remaining($this->owner));
        $run = $this->em->getRepository(AgentRun::class)->findOneBy([]);
        self::assertSame('live', $run->mode);
        $this->client->submit($form); // Same request twice must not consume twice or queue twice.
        self::assertSame(1, $this->em->getRepository(AgentRun::class)->count([]));
        $run = $this->em->find(AgentRun::class, $run->id);
        $run->status = 'completed'; $run->modelCalls = 1; $this->em->flush();
        $page = $this->client->request('GET', '/');
        self::assertSelectorTextContains('[data-testid="search-allowance"]', '0');
        self::assertSelectorExists('button[type="submit"].primary[disabled]');
        $this->client->submit($page->filter('form[name="run"]')->form());
        self::assertResponseRedirects('/'); self::assertSame(1, $this->em->getRepository(AgentRun::class)->count([]));
    }
    public function testAtomicReservationRejectsASecondRunEvenWithAStaleUserObject(): void
    {
        $allowance = new SearchAllowance($this->em); $a = new AgentRun($this->owner); $b = new AgentRun($this->owner);
        self::assertTrue($allowance->reserve($this->owner, $a));
        self::assertFalse($allowance->reserve($this->owner, $b));
        self::assertTrue($allowance->permits($a)); self::assertFalse($allowance->permits($b));
        $a->status = 'failed'; $allowance->releaseUnused($a);
        self::assertTrue($allowance->reserve($this->owner, $b));
        $allowance->releaseUnused($a); // An old refund must not erase a newer reservation.
        self::assertTrue($allowance->permits($b)); self::assertSame(0, $allowance->remaining($this->owner));
    }
    public function testQueueFailureRollsBackTheRunAndFreeSearch(): void
    {
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Queue unavailable'));
        $launcher = new SearchLauncher($this->em, $bus, new SearchAllowance($this->em));
        $profile = $this->em->getRepository(Profile::class)->findOneBy(['user' => $this->owner]);
        try { $launcher->start($this->owner, $profile, ['sources' => ['solidjobs'], 'minimumScore' => 65], 'uk'); self::fail('Expected dispatch failure.'); }
        catch (\RuntimeException $error) { self::assertSame('Queue unavailable', $error->getMessage()); }
        self::assertSame(0, $this->em->getRepository(AgentRun::class)->count([]));
        self::assertSame(1, (new SearchAllowance($this->em))->remaining($this->owner));
    }
    public function testWorkerRejectsUnreservedRunsWithoutCallingTheModel(): void
    {
        $run = $this->createRun('unreserved'); $run->mode = 'live'; $this->em->flush();
        $this->runner()->run($run);
        self::assertSame('failed', $run->status); self::assertSame(0, $run->modelCalls);
        self::assertSame(1, (new SearchAllowance($this->em))->remaining($this->owner));
    }
    public function testFailedModelAttemptStillUsesTheFreeSearch(): void
    {
        $run = $this->createRun();
        $this->runner(new MockHttpClient(new MockResponse('{}', ['http_code' => 500])))->run($run);
        self::assertSame('failed', $run->status); self::assertSame(1, $run->modelCalls);
        self::assertSame(0, (new SearchAllowance($this->em))->remaining($this->owner));
    }
    public function testMissingApiKeyBeforeWorkerStartReturnsTheReservation(): void
    {
        $run = $this->createRun(); $this->runner(apiKey: '')->run($run);
        self::assertSame('failed', $run->status); self::assertSame(0, $run->modelCalls);
        self::assertSame(1, (new SearchAllowance($this->em))->remaining($this->owner));
    }
    public function testResetErasesTestDataButKeepsTheExactDemoLogin(): void
    {
        $run = $this->createRun(); $this->fixtureMatch($run); $password = $this->owner->password;
        $command = new \App\Command\ResetTestDataCommand($this->em->getConnection());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        self::assertSame(2, $tester->execute(['--keep-user' => $this->owner->email]));
        self::assertSame(2, $this->em->getRepository(User::class)->count([]));
        self::assertSame(0, $tester->execute(['--keep-user' => $this->owner->email, '--confirm' => 'RESET_WORKLOOK_TEST_DATA']));
        $id = $this->owner->id; $this->em->clear();
        $owner = $this->em->find(User::class, $id);
        self::assertSame($password, $owner->password); self::assertNull($owner->freeSearchRunId);
        self::assertSame(1, $this->em->getRepository(User::class)->count([]));
        foreach ([AgentRun::class, JobMatch::class, Job::class, Profile::class] as $entity) { self::assertSame(0, $this->em->getRepository($entity)->count([])); }
    }
    public function testRealAgentProtocolChoosesToolsAndReplaysOutputs(): void
    {
        $run = $this->createRun('live'); $fact = $run->profileSnapshot['facts'][0]['id']; $step = 0;
        $run->profileSnapshot['ui_locale'] = 'pl';
        $modelHttp = new MockHttpClient(function ($method, $url, $options) use (&$step, $fact) {
            self::assertSame('POST', $method); self::assertSame('https://api.openai.com/v1/responses', $url);
            $body = json_decode($options['body'], true); self::assertFalse($body['store']);
            self::assertStringContainsString('final answer in Polish', $body['input'][0]['content']);
            if ($step > 0) { self::assertSame('function_call_output', end($body['input'])['type']); }
            $jobId = $this->em->getRepository(Job::class)->findOneBy(['source' => 'solidjobs'])?->id ?? 0;
            [$name, $args] = match ($step++) {
                0 => ['get_my_profile', []], 1 => ['search_jobs', ['source' => 'solidjobs', 'keywords' => 'PHP', 'page' => 0]],
                2 => ['get_job_details', ['job_id' => $jobId]],
                3 => ['save_job_match', ['job_id' => $jobId, 'score' => 90, 'reasons' => ['PHP зазначено у профілі.'], 'gaps' => ['Право працювати з Польщі потребує перевірки.']]],
                4 => ['prepare_cv', ['job_id' => $jobId, 'fact_ids' => [$fact]]], default => ['', []],
            };
            $output = $name ? [['type' => 'function_call', 'call_id' => 'call_'.$step, 'name' => $name, 'arguments' => json_encode($args ?: new \stdClass())]]
                : [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Знайдено вакансію; перевірте умови.']]]];
            return new MockResponse(json_encode(['status' => 'completed', 'output' => $output, 'usage' => ['total_tokens' => 100]]));
        });
        $runner = $this->runner($modelHttp); $runner->run($run); $runner->run($run);
        self::assertSame('completed', $run->status); self::assertSame(6, $run->modelCalls); self::assertSame(5, $run->toolCalls);
        self::assertSame(600, $run->totalTokens); self::assertSame([$fact], $this->em->getRepository(JobMatch::class)->findOneBy(['run' => $run])->selectedFactIds);
    }
}
