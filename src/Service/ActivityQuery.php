<?php
declare(strict_types=1);
namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

final class ActivityQuery
{
    public const KINDS = ['page_view', 'http_request', 'login_success', 'login_failure', 'logout', 'admin_action'];
    public function __construct(private Connection $db) {}

    public function filters(Request $request): array
    {
        $days = (int) $request->query->getString('days', '7');
        $kind = $request->query->getString('kind');
        $country = strtoupper($request->query->getString('country'));
        return ['days' => in_array($days, [1, 7, 30, 90], true) ? $days : 7,
            'kind' => in_array($kind, self::KINDS, true) ? $kind : '',
            'country' => preg_match('/^[A-Z]{2}$/D', $country) ? $country : '',
            'q' => mb_substr(trim($request->query->getString('q')), 0, 180),
            'errors' => $request->query->getString('errors') === '1' ? '1' : '',
            'page' => max(1, min(10000, (int) $request->query->getString('page', '1')))];
    }
    public function report(array $filters): array
    {
        $since = (new \DateTimeImmutable('-'.$filters['days'].' days', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $base = 'recorded_at >= :since'; $params = ['since' => $since];
        $web = $this->db->fetchAssociative("SELECT COUNT(CASE WHEN kind = 'page_view' THEN 1 END) AS views,
            COUNT(DISTINCT CASE WHEN kind = 'page_view' THEN ip END) AS visitors,
            COUNT(CASE WHEN kind = 'login_success' THEN 1 END) AS logins,
            COUNT(CASE WHEN kind = 'login_failure' THEN 1 END) AS failures,
            COUNT(CASE WHEN status_code >= 500 THEN 1 END) AS errors
            FROM activity_event WHERE $base", $params);
        $countries = $this->db->fetchAllAssociative("SELECT country, COUNT(*) AS views, COUNT(DISTINCT ip) AS visitors FROM activity_event
            WHERE $base AND kind = 'page_view' GROUP BY country ORDER BY views DESC LIMIT 12", $params);
        $sources = $this->db->fetchAllAssociative("SELECT referrer_host, COUNT(*) AS views FROM activity_event
            WHERE $base AND kind = 'page_view' GROUP BY referrer_host ORDER BY views DESC LIMIT 8", $params);
        $daily = $this->db->fetchAllAssociative("SELECT SUBSTR(CAST(recorded_at AS VARCHAR), 1, 10) AS day,
            COUNT(CASE WHEN kind = 'page_view' THEN 1 END) AS views,
            COUNT(CASE WHEN kind = 'login_success' THEN 1 END) AS logins
            FROM activity_event WHERE $base GROUP BY SUBSTR(CAST(recorded_at AS VARCHAR), 1, 10) ORDER BY day", $params);
        $byDate = array_column($daily, null, 'day'); $daily = [];
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        for ($date = new \DateTimeImmutable(substr($since, 0, 10), new \DateTimeZone('UTC')); $date <= $today; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d'); $daily[] = $byDate[$key] ?? ['day' => $key, 'views' => 0, 'logins' => 0];
        }
        $where = $base;
        foreach (['kind', 'country'] as $field) {
            if ($filters[$field] !== '') { $where .= ' AND '.$field.' = :'.$field; $params[$field] = $filters[$field]; }
        }
        if ($filters['errors']) { $where .= " AND (status_code >= 400 OR kind = 'login_failure')"; }
        if ($filters['q'] !== '') {
            $where .= " AND (LOWER(actor_name) LIKE :q ESCAPE '!' OR actor_id = :exact OR ip = :exact OR request_id = :exact)";
            $params['q'] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q'])).'%'; $params['exact'] = $filters['q'];
        }
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM activity_event WHERE '.$where, $params);
        $page = min($filters['page'], max(1, (int) ceil($count / 50)));
        $events = $this->db->fetchAllAssociative('SELECT * FROM activity_event WHERE '.$where.' ORDER BY recorded_at DESC, id DESC LIMIT 50 OFFSET '.(($page - 1) * 50), $params);
        foreach ($events as &$event) { $event['context'] = json_decode($event['context'], true) ?? []; }
        unset($event);
        return compact('web', 'countries', 'sources', 'daily', 'events', 'count', 'page');
    }
}
