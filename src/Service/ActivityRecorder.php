<?php
declare(strict_types=1);
namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

final class ActivityRecorder
{
    public function __construct(private Connection $db, private IpLocation $location, private LoggerInterface $logger, private TokenStorageInterface $tokens) {}

    public function record(Request $request, string $kind, int $status, ?User $user = null, array $context = []): bool
    {
        try {
            $ip = $request->getClientIp();
            if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) { $ip = null; }
            $geo = $this->location->locate($ip);
            $route = $request->attributes->get('_route', '');
            $route = is_string($route) ? $route : '';
            // Unknown URLs, query strings, cookies, request bodies and auth headers are never persisted.
            $path = $route !== '' ? $request->getPathInfo() : '[unmatched]';
            $referrer = parse_url($request->headers->get('Referer', ''), PHP_URL_HOST);
            $context += $this->client($request->headers->get('User-Agent', ''));
            $context += $request->attributes->get('_activity_impersonation', []);
            $token = $this->tokens->getToken();
            if ($token instanceof SwitchUserToken && $token->getOriginalToken()->getUser() instanceof User && $token->getUser() instanceof User) {
                $context += ['impersonator_user_id' => $token->getOriginalToken()->getUser()->id, 'effective_user_id' => $token->getUser()->id];
            }
            $actorId = $user?->id ? (string) $user->id : null;
            $actorName = $user?->email;
            if ($kind === 'login_failure') {
                $candidate = $request->request->get('_username');
                $actorName = is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_EMAIL) ? $candidate : null;
            }
            $this->db->insert('activity_event', [
                'id' => bin2hex(random_bytes(16)), 'recorded_at' => gmdate('Y-m-d H:i:s'),
                'kind' => $kind, 'request_id' => $request->attributes->get('_activity_id', bin2hex(random_bytes(16))),
                'actor_id' => $actorId, 'actor_name' => $actorName !== null ? mb_substr($actorName, 0, 180) : null,
                'ip' => $ip, 'country' => $geo['country'], 'city' => $geo['city'], 'route_name' => substr($route, 0, 80),
                'path' => mb_substr($path, 0, 255), 'method' => substr($request->getMethod(), 0, 8), 'status_code' => $status,
                'duration_ms' => max(0, (int) round((microtime(true) - $request->attributes->get('_activity_start', microtime(true))) * 1000)),
                'referrer_host' => is_string($referrer) ? substr($referrer, 0, 255) : null,
                'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            return true;
        } catch (\Throwable $error) {
            // Logging failure must never change webhook acknowledgements or expose credentials.
            $this->logger->error('Activity record unavailable', ['exception_type' => $error::class]);
            return false;
        }
    }

    private function client(string $agent): array
    {
        $browser = match (true) {
            (bool) preg_match('/bot|crawler|spider|Headless/i', $agent) => 'bot',
            str_contains($agent, 'Edg/') => 'Edge', str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari', default => 'other',
        };
        return ['browser' => $browser, 'device' => preg_match('/Mobile|Android|iPhone/i', $agent) ? 'mobile' : 'desktop'];
    }
}
