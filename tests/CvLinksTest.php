<?php
declare(strict_types=1);
namespace App\Tests;

use App\Service\CvLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CvLinksTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testExactDestinationsAndReadableLabelsArePreserved(string $text,array $destinations,string $visible): void
    {
        $links=new CvLinks();$html=(string)$links->render($text);
        self::assertSame($destinations,$links->extract($text));
        self::assertSame($visible,html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
        foreach($destinations as $url){self::assertStringContainsString('href="'.htmlspecialchars($url,ENT_QUOTES,'UTF-8').'"',$html);}
    }
    public static function addresses(): iterable
    {
        yield 'Profiles without protocol'=>['github.com/example | www.linkedin.com/in/example/',['https://github.com/example','https://www.linkedin.com/in/example/'],'github.com/example | www.linkedin.com/in/example/'];
        yield 'Website and email'=>['example.dev | example+cv@test.local',['https://example.dev','mailto:example+cv@test.local'],'example.dev | example+cv@test.local'];
        yield 'Markdown query and parentheses'=>['[Project & code](https://example.test/project_(demo)?ref=cv&lang=en)',['https://example.test/project_(demo)?ref=cv&lang=en'],'Project & code'];
        yield 'Prose punctuation'=>['See (https://example.test/project_(demo)), then https://example.test/docs.',['https://example.test/project_(demo)','https://example.test/docs'],'See (https://example.test/project_(demo)), then https://example.test/docs.'];
        yield 'Historical HTTP'=>['http://example.test/portfolio',['http://example.test/portfolio'],'http://example.test/portfolio'];
        yield 'Mailto'=>['mailto:example@test.local',['mailto:example@test.local'],'mailto:example@test.local'];
        yield 'Programming names are not websites'=>['PHP, Node.js, Next.js, Vue.js, Symfony 5/6',[],'PHP, Node.js, Next.js, Vue.js, Symfony 5/6'];
        yield 'Unsafe markdown'=>['[Do not execute](javascript:alert(1))',[],'[Do not execute](javascript:alert(1))'];
    }
    #[DataProvider('unsafeTargets')]
    public function testUnsafeDestinationsRemainPlainText(string $value): void
    {
        $links=new CvLinks();self::assertNull($links->target($value));
        self::assertStringNotContainsString('<a ',(string)$links->link($value));
    }
    public static function unsafeTargets(): iterable
    {
        foreach(['javascript:alert(1)','javascript:123','data:text/html,test','file:///etc/passwd','ftp://example.test/file',
            'https://user:password@example.test','https://example.test/a" onclick="alert(1)',"https://example.test/a\nb",'mailto:example@test.local?subject=Injected','mailto:example@test.local%0d%0aBcc:other@test.local'] as $value){yield [$value];}
    }
    public function testHtmlIsEscapedWhileAnAllowedDestinationRemainsClickable(): void
    {
        $html=(string)(new CvLinks())->render('<script>alert(1)</script> [<img src=x>](https://example.test/a?x=1&y=2)');
        self::assertStringNotContainsString('<script>',$html);self::assertStringNotContainsString('<img ',$html);
        self::assertStringContainsString('&lt;img src=x&gt;',$html);
        self::assertStringContainsString('href="https://example.test/a?x=1&amp;y=2"',$html);
    }
}
