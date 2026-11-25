<?php
declare(strict_types=1);
namespace App\Tests;

use App\Service\{BrandPalette, CvPdfRenderer, PreparedCvLayout};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\{Environment, Loader\FilesystemLoader};

final class PreparedCvLayoutTest extends TestCase
{
    #[DataProvider('documents')]
    public function testLayoutPreservesAllSourceLinesInOrder(string $text): void
    {
        $layout=(new PreparedCvLayout())->parse($text);
        $actual=[$layout['name'],$layout['headline'],...$layout['contacts']];
        foreach($layout['rows'] as $row){
            foreach($row as $section){
                $actual[]=$section['heading'];
                foreach($section['blocks'] as $block){
                    array_push($actual,...($block['items']??explode("\n",$block['text'])));
                }
            }
        }
        $normalize=static function(array $lines):array{
            $lines=array_map(static fn($line)=>trim(preg_replace('/^#{1,6}\s+|^[-*•]\s+|:$/u','',trim($line))),$lines);
            return array_values(array_filter($lines,static fn($line)=>$line!==''));
        };
        self::assertSame($normalize(preg_split('/\R/u',$text)),$normalize($actual));
    }
    public static function documents(): iterable
    {
        yield 'Polish'=>["Jan Przykład\nProgramista\nWarszawa\njan@example.test\n\nPROFIL\nTworzę aplikacje PHP.\nTECHNOLOGIE I UMIEJĘTNOŚCI\nPHP, Symfony\nMySQL\nDOŚWIADCZENIE\nFirma A\nProgramista | 2021 - 2026\n- Integracja REST API.\n• Testy jednostkowe.\nWYKSZTAŁCENIE\nLicencjat\nUczelnia | 2010 - 2013\nJĘZYKI\nPolski - B2"];
        yield 'Ukrainian markdown headings'=>["ТЕСТОВИЙ КАНДИДАТ\nРозробник\n\n## ПРОФІЛЬ:\nПідтверджений досвід.\n## НАВИЧКИ\nPHP\n## ДОСВІД РОБОТИ\nКомпанія\n2020 - 2026\n* Розробка застосунків.\n## ОСВІТА\nКурс\n## МОВИ\nУкраїнська"];
        yield 'English'=>["Jane Example\nDeveloper\n\nSummary\nVerified experience.\nTechnical Skills\nPHP\nWork Experience\nExample Ltd\nDeveloper | 2020 - 2026\n- Tested software.\nPersonal projects\nA personal project\nCertifications\nA course\nEducation\nA degree\nLanguages\nEnglish"];
        yield 'Unstructured'=>["Jane Example\nDeveloper\nA custom introduction\n\nOTHER DETAILS\nA factual detail.\n- A second detail."];
        yield 'Long introduction'=>["Jane Example\nDeveloper\n".str_repeat("An additional factual line.\n",30)."EXPERIENCE\nVerified role."];
        yield 'No short header'=>[str_repeat('A long factual paragraph. ',35)."\n\nAdditional information."];
        yield 'Empty'=>[''];
    }
    public function testUntrustedResumeTextRemainsEscapedInTheTemplate(): void
    {
        $text="Example <script>alert(1)</script>\nDeveloper\n\nSKILLS\n<img src=\"https://example.test/private\">\nEXPERIENCE\n- <a href=\"javascript:alert(1)\">Untrusted text</a>";
        $html=$this->twig()->render('cv/prepared.html.twig',[
            'cv'=>(new PreparedCvLayout())->parse($text),'cvBrandUrl'=>'https://worklook.test/',
        ]);
        self::assertStringNotContainsString('<script>',$html);
        self::assertStringNotContainsString('<img ',$html);
        self::assertStringContainsString('&lt;img',$html);
        self::assertSame(1,substr_count($html,'<a href='));
        self::assertStringContainsString('href="https://worklook.test/"',$html);
    }
    #[DataProvider('origins')]
    public function testFooterUsesOnlyTrustedHttpsOrigin(string $origin,string $expected): void
    {
        $twig=$this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(static function(string $template,array $variables)use($expected):string{
            self::assertSame($expected,$variables['cvBrandUrl']);
            return '<html><body>Example PDF</body></html>';
        });
        $bytes=(new CvPdfRenderer($twig,dirname(__DIR__),$origin))->renderPrepared('Example');
        self::assertStringStartsWith('%PDF-',$bytes);
    }
    public static function origins(): iterable
    {
        yield 'Site'=>['https://worklook.test','https://worklook.test/'];
        yield 'Site with slash'=>['https://worklook.test/','https://worklook.test/'];
        yield 'Empty'=>['',''];
        yield 'Non-HTTPS'=>['http://worklook.test',''];
        yield 'Embedded credentials'=>['https://user:password@worklook.test',''];
        yield 'Query'=>['https://worklook.test/?token=private',''];
        yield 'Path'=>['https://worklook.test/private',''];
        yield 'Script'=>['javascript:alert(1)',''];
    }
    private function twig(): Environment
    {
        $twig=new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'),['strict_variables'=>true]);
        $twig->addGlobal('brand',new BrandPalette(dirname(__DIR__)));
        return $twig;
    }
}
