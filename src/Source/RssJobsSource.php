<?php

namespace App\Source;

final class RssJobsSource
{
    public function __construct(private SourceHttpClient $http) {}

    public function search(string $source, string $keywords, int $page): array
    {
        if ($page !== 0) { throw new \InvalidArgumentException('RSS є стрічкою нових вакансій і не підтримує сторінки.'); }
        [$url, $host, $query] = match ($source) {
            'dou' => ['https://jobs.dou.ua/vacancies/feeds/', 'jobs.dou.ua', ['search' => $keywords]],
            'djinni' => ['https://djinni.co/jobs/rss/', 'djinni.co', ['keywords' => $keywords]],
            default => throw new \InvalidArgumentException('Невідоме RSS-джерело.'),
        };
        $body = $this->http->get($url, $query, 'application/rss+xml, application/xml');
        if (stripos($body, '<!DOCTYPE') !== false || stripos($body, '<!ENTITY') !== false) {
            throw new \RuntimeException('Небезпечні XML declarations у RSS відхилено.');
        }
        $previous = libxml_use_internal_errors(true);
        try { $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if ($xml === false || $xml->getName() !== 'rss' || !isset($xml->channel)) {
            throw new \RuntimeException('Джерело повернуло некоректний RSS.');
        }
        $jobs = [];
        foreach ($xml->channel->item as $item) {
            $url = SourceHttpClient::safeUrl((string) $item->link, $host);
            $jobs[] = ['source' => $source, 'external_id' => hash('sha256', (string) ($item->guid ?: $item->link)),
                'title' => (string) $item->title, 'company' => '', 'url' => $url,
                'description' => SourceHttpClient::plain((string) $item->description), 'locations' => [], 'salaries' => [],
                'skills' => [], 'work_mode' => 'unknown', 'completeness' => 'summary', 'expires_at' => null];
            if (count($jobs) >= 10) { break; }
        }
        return ['jobs' => $jobs, 'next_page' => null, 'coverage' => 'window'];
    }
}
