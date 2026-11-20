<?php

namespace App\Source;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SourceHttpClient
{
    private const HOSTS = ['solid.jobs', 'jobs.dou.ua', 'djinni.co'];

    public function __construct(private HttpClientInterface $http, private CacheInterface $cache) {}

    public function get(string $url, array $query = [], string $accept = 'application/json'): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !in_array(parse_url($url, PHP_URL_HOST), self::HOSTS, true)) {
            throw new \InvalidArgumentException('Джерело не входить до дозволеного списку.');
        }
        return $this->cache->get('jobs_'.hash('sha256', $url.json_encode($query)), function (ItemInterface $item) use ($url, $query, $accept): string {
            $item->expiresAfter(3600);
            $response = $this->http->request('GET', $url, [
                'query' => $query, 'headers' => ['Accept' => $accept, 'User-Agent' => 'WorkLook/0.1 (personal job-search agent)', 'X-Api-Version' => '1.0'],
                'timeout' => 15, 'max_duration' => 25, 'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                $response->cancel();
                throw new \RuntimeException(sprintf('Джерело відповіло HTTP %d. Повторіть запуск пізніше.', $status));
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > 3_000_000) { $response->cancel(); throw new \RuntimeException('Відповідь джерела перевищила 3 MB.'); }
            }
            return $body;
        });
    }

    public static function plain(string $html, int $limit = 20000): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? '';
        $html = preg_replace('~</?(?:p|div|li|br|h[1-6])\b[^>]*>~i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim(preg_replace('/[ \t]+/u', ' ', $text) ?? ''), 0, $limit);
    }

    public static function safeUrl(string $url, string $host): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== $host) {
            throw new \RuntimeException('Джерело повернуло неприпустиме посилання.');
        }
        return $url;
    }
}
