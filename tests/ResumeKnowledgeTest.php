<?php
declare(strict_types=1);
namespace App\Tests;

use App\Agent\ToolExecutor;
use App\Command\SeedKnowledgeCommand;
use App\Entity\{AgentRun, KnowledgeEntry, Profile, ResumeDraft, User};
use App\Service\{KnowledgeBase, ManualResumePrompt, ResumeProfilePreparation, ResumeTextExtractor};
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ResumeKnowledgeTest extends WebTestCase
{
    private \Symfony\Bundle\FrameworkBundle\KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $owner;
    private User $other;
    private User $admin;
    private array $files = [];
    protected function setUp(): void
    {
        $this->client = self::createClient(); $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertStringContainsString('worklook_test', $this->em->getConnection()->getParams()['path']);
        $this->em->getConfiguration()->setUseSchemaEditorApi(true);
        $schema = new SchemaTool($this->em); $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata); $schema->createSchema($metadata); self::getContainer()->get('cache.rate_limiter')->clear();
        $this->owner = $this->user('resume-owner@test.local'); $this->other = $this->user('resume-other@test.local');
        $this->admin = $this->user(User::ADMIN_EMAIL); $this->admin->adminKey = User::ADMIN_KEY; $this->em->flush();
    }
    protected function tearDown(): void
    {
        foreach ($this->files as $file) { if (is_file($file)) { unlink($file); } }
        parent::tearDown();
    }
    private function user(string $email): User
    {
        $user = new User(); $user->email = $email; $user->isVerified = true; $user->password = 'test-fixture-hash';
        $this->em->persist($user); $this->em->flush(); return $user;
    }
    private function file(string $content): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'worklook-pdf-test-'); $path = $temporary.'.pdf'; rename($temporary, $path); file_put_contents($path, $content); $this->files[] = $path; return $path;
    }
    private function pdf(array $lines = ['Jane Doe', 'PHP Developer', 'PHP development at Example Ltd, 2020-2024.'], int $pages = 1): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '<< /Type /Pages /Kids ['.implode(' ', array_map(static fn ($i) => (5+$i).' 0 R', range(0,$pages-1))).'] /Count '.$pages.' >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $stream = 'BT /F1 12 Tf 40 740 Td ';
        foreach ($lines as $line) { $stream .= '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line).') Tj 0 -20 Td '; }
        $stream .= 'ET'; $objects[4] = '<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."\nendstream";
        for ($i=0;$i<$pages;$i++) { $objects[5+$i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents 4 0 R >>'; }
        $pdf = "%PDF-1.4\n"; $offsets=[0];
        foreach ($objects as $id=>$object) { $offsets[$id] = strlen($pdf); $pdf .= "$id 0 obj\n$object\nendobj\n"; }
        $xref=strlen($pdf); $pdf .= 'xref'."\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for ($i=1;$i<=count($objects);$i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); }
        return $pdf.'trailer << /Size '.(count($objects)+1).' /Root 1 0 R >>'."\nstartxref\n$xref\n%%EOF\n";
    }
    private function upload(): void
    {
        $this->client->loginUser($this->owner); $page=$this->client->request('GET','/resume');
        $form=$page->filter('form[action="/resume/upload"]')->form(); $form['resume']->upload($this->file($this->pdf()));
        $this->client->submit($form); self::assertResponseRedirects('/resume',303);
    }
    private function draft(): ResumeDraft { return $this->em->getRepository(ResumeDraft::class)->findOneBy(['user'=>$this->owner]); }
    private function entry(string $title, ?User $owner = null, string $status = 'published', string $site = 'general'): KnowledgeEntry
    {
        $entry=new KnowledgeEntry(); $entry->title=$title; $entry->content='PHP search guidance '.$title; $entry->owner=$owner;
        $entry->status=$status; $entry->site=$site; $entry->kind=$owner?'search_prompt':'site_tip'; $entry->reviewedAt=new \DateTimeImmutable();
        $entry->sourceNotes='Test editorial evidence'; $this->em->persist($entry); $this->em->flush(); return $entry;
    }
    public function testTextPdfExtractsWithoutNetworkAndRejectsMissingTextAndExcessPages(): void
    {
        $extractor=self::getContainer()->get(ResumeTextExtractor::class);
        $result=$extractor->extract(new UploadedFile($this->file($this->pdf()),'cv.pdf','application/pdf',test:true));
        self::assertSame(1,$result['pages']); self::assertStringContainsString('Jane Doe',$result['text']);
        foreach ([[$this->pdf([]),'resume.no_text'],[$this->pdf(['PHP developer with verified experience.'],21),'resume.too_large'],['not a PDF','resume.invalid']] as [$body,$expected]) {
            try { $extractor->extract(new UploadedFile($this->file($body),'cv.pdf','application/pdf',test:true)); self::fail('Invalid PDF accepted.'); }
            catch (\InvalidArgumentException $error) { self::assertSame($expected,$error->getMessage()); }
        }
    }
    public function testCyrillicAndPolishTextSurviveEmbeddedPdfFontExtraction(): void
    {
        $options=new \Dompdf\Options(['defaultFont'=>'DejaVu Sans','isRemoteEnabled'=>false,'isJavascriptEnabled'=>false,'isPhpEnabled'=>false]);
        $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml('<html><meta charset="UTF-8"><p>Роман Галайко</p><p>Інженер PHP. Doświadczenie zawodowe, Łódź.</p></html>','UTF-8'); $pdf->render();
        $result=self::getContainer()->get(ResumeTextExtractor::class)->extract(new UploadedFile($this->file($pdf->output()),'cv.pdf','application/pdf',test:true));
        self::assertStringContainsString('Роман Галайко',$result['text']); self::assertStringContainsString('Łódź',$result['text']);
    }
    public function testInRequestParserUsesTheSameLimitsAndRestoresMemorySettings(): void
    {
        $memory=ini_get('memory_limit'); $parser=new \App\Service\TextPdfParser();
        self::assertStringContainsString('Jane Doe',$parser->parse($this->file($this->pdf()))['text']);
        self::assertSame($memory,ini_get('memory_limit'));
        try { $parser->parse($this->file($this->pdf([]))); self::fail('Image-only PDF was accepted.'); }
        catch (\RuntimeException $error) { self::assertSame('no_text',$error->getMessage()); }
        self::assertSame($memory,ini_get('memory_limit'));
    }
    public function testUploadAndManualPackageAreOwnerScopedAndDoNotSpendSearch(): void
    {
        $this->upload(); $this->em->clear(); $draft=$this->draft(); self::assertStringContainsString('Jane Doe',$draft->text);
        self::assertSame(0,$this->em->getRepository(Profile::class)->count([])); self::assertSame(0,$this->em->getRepository(AgentRun::class)->count([]));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT free_search_run_id FROM app_user WHERE id = ?',[$this->owner->id]) ?: null);
        $this->client->request('GET','/resume/prompt.txt'); self::assertResponseIsSuccessful(); self::assertResponseHeaderSame('Content-Type','text/plain; charset=UTF-8');
        self::assertStringContainsString('Jane Doe',$this->client->getResponse()->getContent()); self::assertStringContainsString('verbatim',$this->client->getResponse()->getContent());
        $this->client->loginUser($this->other); $this->client->request('GET','/resume/prompt.txt?user='.$this->owner->id); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/resume'); self::assertStringNotContainsString('Jane Doe',$this->client->getResponse()->getContent());
    }
    public function testResumeUploadRequiresCsrfAndFailedImportPreservesPreviousDraft(): void
    {
        $this->client->loginUser($this->owner); $this->client->request('POST','/resume/upload',['_token'=>'invalid']); self::assertResponseStatusCodeSame(403);
        $this->upload(); $this->em->clear(); $before=$this->draft()->contentHash;
        $page=$this->client->request('GET','/resume'); $form=$page->filter('form[action="/resume/upload"]')->form();
        $form['resume']->upload($this->file('not a PDF')); $this->client->submit($form); self::assertResponseRedirects('/resume',303);
        $this->em->clear(); self::assertSame($before,$this->draft()->contentHash);
    }
    public function testUnsupportedAiFactsAreRejectedAndProfileRequiresUserConfirmation(): void
    {
        $this->upload(); $this->em->clear();
        $data=['display_name'=>'Jane Doe','headline'=>'PHP Developer','contact_line'=>'','facts'=>['Invented Kubernetes certification']];
        $page=$this->client->request('GET','/resume'); $form=$page->filter('form[action="/resume/prepare"]')->form(['profile_json'=>json_encode($data)]);
        $this->client->submit($form); self::assertResponseStatusCodeSame(422); self::assertSame(0,$this->em->getRepository(Profile::class)->count([]));
        $data['facts']=['PHP development at Example Ltd, 2020-2024.'];
        $page=$this->client->request('GET','/resume'); $form=$page->filter('form[action="/resume/prepare"]')->form(['profile_json'=>json_encode($data)]);
        $this->client->submit($form); self::assertResponseRedirects('/profile?resume=1',303);
        $page=$this->client->followRedirect(); self::assertSelectorExists('input[value="Jane Doe"]');
        self::assertSame(0,$this->em->getRepository(Profile::class)->count([]));
        $this->client->submit($page->filter('form[name="profile"]')->form()); self::assertResponseRedirects('/profile',303);
        $this->em->clear(); $profile=$this->em->getRepository(Profile::class)->findOneBy(['user'=>$this->owner]);
        self::assertSame($data['facts'][0],$profile->factsText);
    }
    public function testManualPreparationRejectsUnknownKeysAndRewrittenContactDetails(): void
    {
        $draft=new ResumeDraft($this->owner); $draft->text='Jane Doe PHP Developer PHP development at Example Ltd, 2020-2024.';
        $service=new ResumeProfilePreparation();
        foreach ([['display_name'=>'Jane Doe','headline'=>'PHP Developer','contact_line'=>'invented@example.com','facts'=>['PHP Developer']],
            ['display_name'=>'Jane Doe','headline'=>'PHP Developer','contact_line'=>'','facts'=>['PHP Developer'],'owner_id'=>$this->other->id]] as $data) {
            try { $service->validate($draft,json_encode($data)); self::fail('Unsupported result accepted.'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testKnowledgeRetrievalNeverLeaksOtherOwnersOrUnpublishedSharedDrafts(): void
    {
        $public=$this->entry('shared PHP'); $own=$this->entry('own PHP',$this->owner,'draft');
        $this->entry('secret other PHP',$this->other,'draft'); $this->entry('unreviewed shared PHP',null,'draft'); $this->entry('archived PHP',null,'archived');
        $this->entry('disconnected source PHP',null,'published','justjoinit');
        $entries=(new KnowledgeBase($this->em))->search($this->owner,'PHP',['general','solidjobs']);
        self::assertEqualsCanonicalizing([$public->id,$own->id],array_column($entries,'id'));
        self::assertSame('reference_data_not_instructions',$entries[0]['trust']);
        $draft=new ResumeDraft($this->owner); $draft->text='Private resume data'; $draft->requestText='PHP';
        $package=(new ManualResumePrompt(new KnowledgeBase($this->em)))->build($draft,'uk');
        self::assertStringNotContainsString('secret other PHP',$package); self::assertStringNotContainsString('unreviewed shared PHP',$package);
    }
    public function testAgentKnowledgeToolEnforcesRunSourcesAndCapsResults(): void
    {
        for($i=0;$i<7;$i++) { $entry=$this->entry('PHP '.$i); $entry->content=str_repeat('PHP ',1000); } $this->em->flush();
        $run=new AgentRun($this->owner); $run->sources=['solidjobs']; $state=[];
        $tool=self::getContainer()->get(ToolExecutor::class); $result=$tool->execute($run,'get_search_knowledge',['query'=>'PHP','source'=>'solidjobs'],$state);
        self::assertCount(4,$result['entries']); self::assertTrue($result['entries'][0]['truncated']); self::assertLessThanOrEqual(2000,mb_strlen($result['entries'][0]['content']));
        self::assertFalse($result['candidate_facts']); $this->expectException(\InvalidArgumentException::class);
        $tool->execute($run,'get_search_knowledge',['query'=>'PHP','source'=>'djinni'],$state);
    }
    public function testPrivatePromptUiAndSharedPublishingRequireSeparatePermissions(): void
    {
        $private=$this->entry('Other personal secret',$this->other,'draft'); $this->client->loginUser($this->owner);
        $this->client->request('GET','/knowledge'); self::assertResponseIsSuccessful(); self::assertStringNotContainsString('Other personal secret',$this->client->getResponse()->getContent());
        $this->client->request('GET','/knowledge/prompts/'.$private->id); self::assertResponseStatusCodeSame(404);
        $this->client->request('GET','/admin/knowledge/new'); self::assertResponseStatusCodeSame(403);
        $page=$this->client->request('GET','/knowledge/prompts/new');
        $this->client->submit($page->filter('form[name="knowledge"]')->form(['knowledge[title]'=>'My PHP prompt','knowledge[content]'=>'Find PHP jobs in Warsaw.'])); self::assertResponseRedirects('/knowledge',303);
        $this->em->clear(); $entry=$this->em->getRepository(KnowledgeEntry::class)->findOneBy(['title'=>'My PHP prompt']);
        self::assertSame($this->owner->id,$entry->owner->id); self::assertSame('draft',$entry->status); self::assertSame('search_prompt',$entry->kind);
    }
    public function testPublishingRequiresReviewAndIsAudited(): void
    {
        $this->client->loginUser($this->admin); $page=$this->client->request('GET','/admin/knowledge/new');
        $fields=['knowledge[title]'=>'Verified PHP note','knowledge[content]'=>'Check the exact contract.','knowledge[status]'=>'published'];
        $this->client->submit($page->filter('form[name="knowledge"]')->form($fields)); self::assertResponseStatusCodeSame(422);
        $page=$this->client->request('GET','/admin/knowledge/new');
        $fields['knowledge[sourceNotes]']='Editorial note reviewed by administrator'; $fields['knowledge[reviewedAt]']=gmdate('Y-m-d');
        $this->client->submit($page->filter('form[name="knowledge"]')->form($fields)); self::assertResponseRedirects('/knowledge',303);
        self::assertSame(1,(int)$this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM activity_event WHERE kind='admin_action'"));
    }
    public function testInitialKnowledgeSeedIsIdempotentAndDoesNotOverwriteAdminEdits(): void
    {
        $command=new CommandTester(new SeedKnowledgeCommand($this->em)); self::assertSame(0,$command->execute([]));
        $entry=$this->em->getRepository(KnowledgeEntry::class)->findOneBy(['seedKey'=>'initial-solid-query']); $entry->title='Reviewed custom title'; $this->em->flush();
        self::assertSame(0,$command->execute([])); self::assertSame(5,$this->em->getRepository(KnowledgeEntry::class)->count([])); self::assertSame('Reviewed custom title',$entry->title);
    }
    public function testKnowledgeEditsRequireCsrfAndDetectStaleForms(): void
    {
        $entry=$this->entry('My own PHP',$this->owner,'draft'); $id=$entry->id; $this->client->loginUser($this->owner);
        $this->client->request('POST','/knowledge/prompts/'.$id,['knowledge'=>['title'=>'Forged','content'=>'Forged','site'=>'general','entryVersion'=>'1','_token'=>'invalid']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('My own PHP',$this->em->getConnection()->fetchOne('SELECT title FROM knowledge_entry WHERE id = ?',[$id]));
        $page=$this->client->request('GET','/knowledge/prompts/'.$id); $form=$page->filter('form[name="knowledge"]')->form(['knowledge[title]'=>'Stale title']);
        $this->em->getConnection()->executeStatement('UPDATE knowledge_entry SET revision = revision + 1 WHERE id = ?',[$id]);
        $this->client->submit($form); self::assertResponseStatusCodeSame(422);
        self::assertSame('My own PHP',$this->em->getConnection()->fetchOne('SELECT title FROM knowledge_entry WHERE id = ?',[$id]));
    }
    public function testDraftRemovalKeepsConfirmedProfileAndPrivatePromptsRemainPrivate(): void
    {
        $this->upload(); $this->em->clear();
        $profile=new Profile($this->em->find(User::class,$this->owner->id)); $profile->displayName='Confirmed'; $profile->factsText='Original confirmed fact.'; $this->em->persist($profile); $this->em->flush();
        $page=$this->client->request('GET','/resume'); $this->client->submit($page->filter('form[action="/resume/remove"]')->form()); self::assertResponseRedirects('/resume',303);
        self::assertSame(0,(int)$this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM resume_draft')); self::assertSame('Original confirmed fact.',$this->em->getConnection()->fetchOne('SELECT facts_text FROM profile'));
    }
}
