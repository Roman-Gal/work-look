<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{AgentRun, KnowledgeEntry, LocalWorkRequest, ResumeDraft, User};
use App\Service\{LocalWork, ResultNotification};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LocalWorkTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $other;
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    protected function setUp(): void
    {
        $_SERVER['LOCAL_BATCH_ENABLED']=$_ENV['LOCAL_BATCH_ENABLED']='1';
        $_SERVER['LOCAL_WORKER_TOKEN_SHA256']=$_ENV['LOCAL_WORKER_TOKEN_SHA256']=hash('sha256',self::TOKEN);
        $this->client=self::createClient(); $this->em=self::getContainer()->get(EntityManagerInterface::class);
        self::assertStringContainsString('worklook_test',$this->em->getConnection()->getParams()['path']);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $tool=new SchemaTool($this->em); $meta=$this->em->getMetadataFactory()->getAllMetadata(); $tool->dropSchema($meta); $tool->createSchema($meta);
        $this->owner=$this->user('local-owner@test.local'); $this->other=$this->user('local-other@test.local');
        self::getContainer()->get('cache.rate_limiter')->clear();
    }
    protected function tearDown(): void
    {
        parent::tearDown(); $_SERVER['LOCAL_BATCH_ENABLED']=$_ENV['LOCAL_BATCH_ENABLED']='0';
    }
    private function user(string $email): User
    {
        $u=new User(); $u->email=$email; $u->password='test-hash'; $u->isVerified=true; $this->em->persist($u); $this->em->flush(); return $u;
    }
    private function draft(): ResumeDraft
    {
        $d=new ResumeDraft($this->owner); $d->text="Jane Doe\nPHP Developer\nPHP development at Example Ltd, 2021-present.";
        $d->pdfData='%PDF-1.4 synthetic stored bytes'; $d->contentHash=hash('sha256',$d->pdfData); $d->filename='resume.pdf'; $d->requestText='PHP and AI';
        $d->privacyAcknowledgedAt=new \DateTimeImmutable(); $d->privacyVersion=LocalWork::PRIVACY_VERSION;
        $this->em->persist($d); $this->em->flush(); return $d;
    }
    private function job(): LocalWorkRequest { return self::getContainer()->get(LocalWork::class)->start($this->draft(),'uk'); }
    private function worker(string $method,string $path,?array $body=null,bool $reviewed=true): void
    {
        $headers=['HTTP_AUTHORIZATION'=>'Bearer '.self::TOKEN,'CONTENT_TYPE'=>'application/json'];
        if ($reviewed) { $headers['HTTP_X_OPERATOR_REVIEWED']='yes'; }
        $this->client->request($method,'/internal/local-work/'.$path,server:$headers,content:$body===null?null:json_encode($body,JSON_THROW_ON_ERROR));
    }
    private function validResult(LocalWorkRequest $job): array
    {
        return ['request_id'=>$job->id,'source_sha256'=>$job->input['source_sha256'],'summary'=>'Verified shortlist.', 'matches'=>[
            ['title'=>'PHP Developer','company'=>'Example','url'=>'https://example.com/jobs/php','location'=>'Remote Poland','salary'=>'Unknown','fit'=>'PHP','gaps'=>'React unknown',
            'checked_at'=>date('Y-m-d'),'cv_text'=>"Jane Doe\nPHP Developer\nPHP development at Example Ltd, 2021-present.",'evidence'=>['PHP development at Example Ltd, 2021-present.']]
        ]];
    }
    public function testStoredPdfAndSnapshotsArePrivateAndAllowanceIsUntouched(): void
    {
        $draft=$this->draft(); $bytes=$draft->pdfBytes(); $this->em->clear();
        $this->client->loginUser($this->owner); $this->client->request('GET','/resume/original.pdf');
        self::assertResponseIsSuccessful(); self::assertSame($bytes,$this->client->getResponse()->getContent()); self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        $page=$this->client->request('GET','/'); self::assertSelectorTextContains('h1','Пошук роботи');
        self::assertStringNotContainsString('AI ще не підключено',$this->client->getResponse()->getContent());
        $form=$page->filter('form[action="/requests/new"]')->form(); $this->client->submit($form); self::assertResponseStatusCodeSame(303);
        $location=$this->client->getResponse()->headers->get('Location');
        $this->client->request('GET','/'); $page=$this->client->getCrawler(); $this->client->submit($page->filter('form[action="/requests/new"]')->form());
        self::assertSame($location,$this->client->getResponse()->headers->get('Location'));
        $this->em->clear(); self::assertSame(1,$this->em->getRepository(LocalWorkRequest::class)->count([])); self::assertSame(0,$this->em->getRepository(AgentRun::class)->count([]));
        self::assertNull($this->em->find(User::class,$this->owner->id)->freeSearchRunId);
        $this->client->loginUser($this->other); $this->client->request('GET','/resume/original.pdf'); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET',$location); self::assertResponseStatusCodeSame(404);
    }
    public function testWorkerRequiresItsOwnTokenForEveryEndpointEvenWithUserCookie(): void
    {
        $job=$this->job(); $this->client->loginUser($this->owner);
        foreach ([['GET','manifest'],['GET','knowledge'],['GET',$job->id.'/package'],['GET',$job->id.'/original.pdf'],['POST',$job->id.'/result']] as [$method,$path]) {
            $this->client->request($method,'/internal/local-work/'.$path); self::assertResponseStatusCodeSame(401);
            self::assertStringNotContainsString('Jane Doe',$this->client->getResponse()->getContent());
        }
        $this->worker('GET','manifest'); self::assertResponseIsSuccessful(); self::assertStringNotContainsString('Jane Doe',$this->client->getResponse()->getContent());
        $this->worker('GET',$job->id.'/original.pdf'); self::assertResponseIsSuccessful(); self::assertSame($job->pdfBytes(),$this->client->getResponse()->getContent());
    }
    public function testExportIncludesOnlyOwnersPrivateKnowledgeAndReviewedSharedEntries(): void
    {
        $job=$this->job();
        foreach ([[$this->owner,'Owner private','draft'],[$this->other,'Other private','draft'],[null,'Shared published','published'],[null,'Shared draft','draft']] as [$owner,$title,$status]) {
            $entry=new KnowledgeEntry();$entry->owner=$owner;$entry->title=$title;$entry->content='Useful reference';$entry->status=$status;$entry->reviewedAt=new \DateTimeImmutable('-1 day');$this->em->persist($entry);
        }
        $this->em->flush(); $this->worker('GET',$job->id.'/package'); self::assertResponseIsSuccessful(); $body=$this->client->getResponse()->getContent();
        self::assertStringContainsString('Owner private',$body); self::assertStringContainsString('Shared published',$body); self::assertStringNotContainsString('Other private',$body);self::assertStringNotContainsString('Shared draft',$body);
        $this->worker('GET','knowledge'); $body=$this->client->getResponse()->getContent(); self::assertStringNotContainsString('Owner private',$body); self::assertStringContainsString('Shared published',$body);
    }
    public function testPublishRejectsWrongInputUnsafeUrlsStaleDatesAndInventedEvidence(): void
    {
        $job=$this->job();$valid=$this->validResult($job);
        $this->worker('POST',$job->id.'/result',$valid,false); self::assertResponseStatusCodeSame(400);
        $bad=$valid;$bad['source_sha256']=str_repeat('0',64);$this->worker('POST',$job->id.'/result',$bad);self::assertResponseStatusCodeSame(422);
        foreach ([['url','javascript:alert(1)'],['checked_at','2020-01-01'],['evidence',['Invented Kubernetes expertise']]] as [$key,$value]) {
            $bad=$valid;$bad['matches'][0][$key]=$value;$this->worker('POST',$job->id.'/result',$bad);self::assertResponseStatusCodeSame(422);
        }
        $bad=$valid;$bad['matches'][]=$bad['matches'][0];$this->worker('POST',$job->id.'/result',$bad);self::assertResponseStatusCodeSame(422);
        $this->em->clear();self::assertNull($this->em->find(LocalWorkRequest::class,$job->id)->result);
    }
    public function testReviewedPublishIsIdempotentNotifiesOnceAndProvidesOwnerOnlyPdf(): void
    {
        $job=$this->job();$valid=$this->validResult($job);$this->worker('POST',$job->id.'/result',$valid);self::assertResponseIsSuccessful();
        self::assertEmailCount(1); $email=self::getMailerMessage(); self::assertEmailAddressContains($email,'To',$this->owner->email);
        self::assertStringContainsString('/requests/'.$job->id,$email->getTextBody()); self::assertStringContainsString('Zakon Text',$email->getHtmlBody()); self::assertStringNotContainsString('Jane Doe',$email->getHtmlBody());
        $this->worker('POST',$job->id.'/result',$valid);self::assertResponseIsSuccessful();self::assertEmailCount(0);
        $this->client->loginUser($this->owner); $this->client->request('GET','/requests/'.$job->id); self::assertSelectorTextContains('h2','PHP Developer');
        $this->client->request('GET','/requests/'.$job->id.'/cv/0.pdf');self::assertResponseIsSuccessful();self::assertStringStartsWith('%PDF-',$this->client->getResponse()->getContent());
        $this->client->loginUser($this->other);$this->client->request('GET','/requests/'.$job->id.'/cv/0.pdf');self::assertResponseStatusCodeSame(404);
    }
    public function testOriginalLinksReachThePackageAndPdfEvenWhenReviewedTextOmitsThem(): void
    {
        $job=$this->job();$original=new \Dompdf\Dompdf(new \Dompdf\Options(['isRemoteEnabled'=>false]));
        $original->loadHtml('<p>Jane Doe, PHP development at Example Ltd, 2021-present.</p><p><a href="https://github.com/example-candidate">GitHub</a> <a href="https://www.linkedin.com/in/example-candidate/">LinkedIn</a></p>');
        $original->render();$job->pdfData=$original->output();$job->input['source_sha256']=hash('sha256',$job->pdfData);
        $job->result=$this->validResult($job);$job->status='completed';$this->em->flush();
        $expected=['https://github.com/example-candidate','https://www.linkedin.com/in/example-candidate/'];
        $this->worker('GET',$job->id.'/package');self::assertResponseIsSuccessful();
        self::assertSame($expected,json_decode($this->client->getResponse()->getContent(),true)['input']['resume_links']);
        $this->client->loginUser($this->owner);$this->client->request('GET','/requests/'.$job->id.'/cv/0.pdf');self::assertResponseIsSuccessful();
        $parsed=(new \App\Service\TextPdfParser())->parseBytes($this->client->getResponse()->getContent());
        foreach([...$expected,'https://worklook.test/'] as $url){self::assertContains($url,$parsed['links']);}
        self::assertStringContainsString('Powered by WorkLook',$parsed['text']);self::assertEmailCount(0);
    }
    public function testDeletedRequestDisappearsFromWorkerAndPreservesMainDraft(): void
    {
        $job=$this->job();$this->client->loginUser($this->owner);$page=$this->client->request('GET','/requests/'.$job->id);
        $this->client->submit($page->filter('form[action$="/remove"]')->form());self::assertResponseRedirects('/requests',303);
        $this->worker('GET','manifest'); self::assertSame([],json_decode($this->client->getResponse()->getContent(),true)['requests']);
        $this->em->clear();self::assertSame(1,$this->em->getRepository(ResumeDraft::class)->count([]));
    }
    public function testPrivacyNoticeAndUploadAcknowledgementAreRequired(): void
    {
        $this->client->request('GET','/privacy/cv');self::assertResponseIsSuccessful();self::assertStringContainsString('Roman Halaiko',$this->client->getResponse()->getContent());
        $this->client->loginUser($this->owner);$page=$this->client->request('GET','/resume');$form=$page->filter('form[action="/resume/upload"]')->form();
        $this->client->submit($form);self::assertResponseRedirects('/resume',303);$this->client->followRedirect();self::assertSelectorExists('input[name="privacy_ack"][required]');
        self::assertSame(0,$this->em->getRepository(ResumeDraft::class)->count([]));
    }
    public function testAdministratorSearchDisableAlsoStopsLocalSubmissions(): void
    {
        $this->draft();$this->owner->liveAiEnabled=false;$this->em->flush();$this->client->loginUser($this->owner);
        $page=$this->client->request('GET','/');$this->client->submit($page->filter('form[action="/requests/new"]')->form());
        self::assertResponseRedirects('/',303);$this->em->clear();self::assertSame(0,$this->em->getRepository(LocalWorkRequest::class)->count([]));
    }
    public function testRequestHistoryPaginationRetainsAccessToOlderRequests(): void
    {
        for($i=0;$i<51;$i++){
            $job=new LocalWorkRequest($this->owner);$job->activeUserKey=null;$job->status='completed';$job->pdfData='%PDF-test';$job->input=['search_request'=>'History '.$i];$this->em->persist($job);
        }
        $this->em->flush();$this->client->loginUser($this->owner);
        $this->client->request('GET','/requests');self::assertResponseIsSuccessful();self::assertSelectorCount(50,'.run-row');self::assertSelectorExists('a[href="/requests?page=2"]');
        $this->client->request('GET','/requests?page=2');self::assertResponseIsSuccessful();self::assertSelectorCount(1,'.run-row');
    }
    private function uploadResult(LocalWorkRequest $job,string $body,bool $reviewed=true,bool $authorized=true): void
    {
        $headers=['CONTENT_TYPE'=>'application/octet-stream','HTTP_CONTENT_DISPOSITION'=>'attachment; filename="result.json"'];
        if($reviewed){$headers['HTTP_X_OPERATOR_REVIEWED']='yes';}
        if($authorized){$headers['HTTP_AUTHORIZATION']='Bearer '.self::TOKEN;}
        $this->client->request('POST','/internal/local-work/'.$job->id.'/result',server:$headers,content:$body);
    }
    public function testDocumentPublicationPreservesAuthorizationReviewAndContentValidation(): void
    {
        $job=$this->job();$result=$this->validResult($job);$body=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $this->uploadResult($job,$body,authorized:false);self::assertResponseStatusCodeSame(401);
        $this->uploadResult($job,$body,reviewed:false);self::assertResponseStatusCodeSame(400);
        $bad=$result;$bad['source_sha256']=str_repeat('0',64);$this->uploadResult($job,json_encode($bad));self::assertResponseStatusCodeSame(422);
        $bad=$result;$bad['matches'][0]['evidence']=['Invented commercial React experience'];$this->uploadResult($job,json_encode($bad));self::assertResponseStatusCodeSame(422);
        $this->uploadResult($job,$body);self::assertResponseIsSuccessful();self::assertEmailCount(1);
        $this->uploadResult($job,$body);self::assertResponseIsSuccessful();self::assertEmailCount(0);
    }
    public function testOversizedOrMalformedDocumentCannotPublish(): void
    {
        $job=$this->job();
        $this->uploadResult($job,str_repeat('x',500001));self::assertResponseStatusCodeSame(400);
        $this->uploadResult($job,'not JSON');self::assertResponseStatusCodeSame(400);
        $this->uploadResult($job,'[]');self::assertResponseStatusCodeSame(422);
        $this->em->clear();self::assertNull($this->em->find(LocalWorkRequest::class,$job->id)->result);
    }
}
