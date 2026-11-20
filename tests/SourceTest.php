<?php
namespace App\Tests;

use App\Source\{SourceHttpClient, SolidJobsSource, RssJobsSource};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SourceTest extends TestCase
{
    public static function solidPayload(): array
    {
        return ['pageIndex' => 0, 'totalPages' => 1, 'jobs' => [[
            'jobOfferKey' => 'fixture-job', 'title' => 'PHP Developer', 'company' => 'Fixture Company',
            'url' => 'https://solid.jobs/offer/fixture-job', 'description' => '<p>'.str_repeat('PHP Symfony backend and tests. ', 20).'</p><script>alert(1)</script>',
            'locations' => ['Warszawa'], 'isRemote' => true, 'isHybrid' => false,
            'skills' => [['name' => 'PHP']], 'salary' => ['from' => 100, 'to' => 150, 'currency' => 'PLN', 'period' => 'Hour', 'employmentType' => 'B2B'],
        ]]];
    }
    public function testSalaryPeriodPreservedHtmlRemovedAndResponseCached(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls) { ++$calls; return new MockResponse(json_encode(self::solidPayload())); });
        $source = new SolidJobsSource(new SourceHttpClient($http, new ArrayAdapter()));
        $page = $source->search('PHP', 0); $source->search('PHP', 0);
        self::assertSame(1, $calls); self::assertSame('Hour', $page['jobs'][0]['salaries'][0]['period']);
        self::assertNull($page['jobs'][0]['salaries'][0]['gross_net']);
        self::assertStringNotContainsString('script', $page['jobs'][0]['description']);
        self::assertStringNotContainsString('alert', $page['jobs'][0]['description']);
    }
    public function testRepeatedPageIndexFailsInsteadOfSilentlyDuplicating(): void
    {
        $source = new SolidJobsSource(new SourceHttpClient(new MockHttpClient(new MockResponse(json_encode(self::solidPayload()))), new ArrayAdapter()));
        $this->expectException(\RuntimeException::class); $source->search('PHP', 1);
    }
    public function testRateLimitIsNotTreatedAsEmptySuccess(): void
    {
        $http = new SourceHttpClient(new MockHttpClient(new MockResponse('{}', ['http_code' => 429])), new ArrayAdapter());
        $this->expectException(\RuntimeException::class); $http->get('https://solid.jobs/public-api/offers/IT');
    }
    public function testArbitraryHostsCannotBeFetched(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SourceHttpClient(new MockHttpClient(), new ArrayAdapter()))->get('https://127.0.0.1/private');
    }
    public function testRssRejectsEntities(): void
    {
        $source = new RssJobsSource(new SourceHttpClient(new MockHttpClient(new MockResponse('<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss/>')), new ArrayAdapter()));
        $this->expectException(\RuntimeException::class); $source->search('dou', 'PHP', 0);
    }
    public function testRssKeepsUnknownFieldsAndSummaryClassification(): void
    {
        $rss = '<rss version="2.0"><channel><item><title>PHP role</title><link>https://jobs.dou.ua/companies/fixture/vacancies/1/</link><description><![CDATA[<p>PHP role</p>]]></description></item></channel></rss>';
        $source = new RssJobsSource(new SourceHttpClient(new MockHttpClient(new MockResponse($rss)), new ArrayAdapter()));
        $job = $source->search('dou', 'PHP', 0)['jobs'][0];
        self::assertSame('summary', $job['completeness']); self::assertSame([], $job['salaries']); self::assertSame('unknown', $job['work_mode']);
    }
}
