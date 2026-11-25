<?php
declare(strict_types=1);
namespace App\Tests;

use App\Entity\{LocalWorkRequest,User};
use App\Service\{CvLinks,LocalSearchSettings,LocalWorkProtocol};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalWorkflowProtocolTest extends TestCase
{
    public function testLanguageHintFollowsCvRatherThanInterfaceAndSitesAreBounded(): void
    {
        $settings=new LocalSearchSettings();
        self::assertSame('pl',$settings->sourceLanguageHint('Doświadczenie zawodowe. Umiejętności PHP. Wykształcenie. Języki.','uk'));
        self::assertSame('uk',$settings->sourceLanguageHint('Досвід роботи. Навички. Освіта. Українська.','pl'));
        self::assertSame('en',$settings->sourceLanguageHint('Professional summary. Experience. Skills. Education.','uk'));
        self::assertNull($settings->sourceForUrl('https://solid.jobs.evil.example/offer'));
        self::assertSame('solidjobs',$settings->sourceForUrl('https://solid.jobs/offer/example'));
        self::assertCount(8,LocalSearchSettings::SOURCES);
    }
    #[DataProvider('invalidSettings')]
    public function testInvalidOrEmptySourceAndLanguageSelectionsAreRejected(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);(new LocalSearchSettings())->normalize($options);
    }
    public static function invalidSettings(): iterable
    {
        yield [['selected_sources'=>[]]];yield [['selected_sources'=>['unknown']]];yield [['selected_sources'=>['solidjobs','solidjobs']]];
        yield [['selected_sources'=>[['nested']]]];yield [['requested_language'=>'invalid']];yield [['adaptation_mode'=>'invent_anything']];
    }
    private function fixture(): array
    {
        $job=new LocalWorkRequest(new User());$job->input=['resume_text'=>'PHP development at Example Ltd, 2021-present.','selected_sources'=>['solidjobs'],'requested_language'=>'pl','adaptation_mode'=>'enhanced'];
        $base=['request_id'=>$job->id,'source_sha256'=>str_repeat('a',64),'summary'=>'Podsumowanie.','matches'=>[['url'=>'https://solid.jobs/offer/example','cv_text'=>'CV']]];
        $data=['protocol_version'=>2,'language'=>'pl','candidate_links'=>[['url'=>'https://github.com/example','label'=>'GitHub: projekty','category'=>'code']],
            'source_report'=>[['source'=>'solidjobs','status'=>'checked','details'=>'Sprawdzono.','checked_urls'=>['https://solid.jobs/']]],
            'matches'=>[['source'=>'solidjobs','conclusion'=>'Dopasowano opis. Założenie wymaga potwierdzenia.',
                'adaptations'=>[['kind'=>'inference','description'=>'Podstawowa znajomość narzędzia powiązanego z PHP.','basis'=>'PHP development at Example Ltd, 2021-present.']]]]];
        return [$job,$base,$data];
    }
    public function testEnhancedModeKeepsEachInferenceAndItsQuotedBasis(): void
    {
        [$job,$base,$data]=$this->fixture();$result=(new LocalWorkProtocol())->extendResult($data,$job,$base,['https://github.com/example']);
        self::assertSame('pl',$result['language']);self::assertSame('inference',$result['matches'][0]['adaptations'][0]['kind']);
        self::assertSame($data['matches'][0]['conclusion'],$result['matches'][0]['conclusion']);
    }
    #[DataProvider('invalidResults')]
    public function testLanguageSourceLinkAndAdaptationViolationsCannotPublish(string $change): void
    {
        [$job,$base,$data]=$this->fixture();
        match($change){
            'language'=>$data['language']='uk',
            'strict'=>$job->input['adaptation_mode']='strict',
            'invented_link'=>$data['candidate_links'][0]['url']='https://github.com/invented',
            'missing_source'=>$data['source_report']=[],
            'wrong_source_url'=>$data['source_report'][0]['checked_urls']=['https://evil.example/'],
            'wrong_vacancy'=>$base['matches'][0]['url']='https://other.example/offer',
            'unsupported_basis'=>$data['matches'][0]['adaptations'][0]['basis']='Invented paid Kubernetes production work.',
        };
        $this->expectException(\InvalidArgumentException::class);
        (new LocalWorkProtocol())->extendResult($data,$job,$base,['https://github.com/example']);
    }
    public static function invalidResults(): iterable
    {
        foreach(['language','strict','invented_link','missing_source','wrong_source_url','wrong_vacancy','unsupported_basis'] as $change){yield [$change];}
    }
    public function testClassifiedLinksHaveReadablePolishLabelsAndKeepExactUrls(): void
    {
        $url='https://example.test/certificate/123?exact=1&lang=pl';
        $links=new CvLinks([['url'=>$url,'category'=>'certificate','label'=>'Java — kurs podstawowy']],'pl');
        $html=(string)$links->link($url);
        self::assertStringContainsString('Certyfikat · Java',$html);self::assertStringContainsString('href="https://example.test/certificate/123?exact=1&amp;lang=pl"',$html);
    }
}
