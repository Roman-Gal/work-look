<?php
namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{JsonResponse, Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** One HTTPS hostname can serve the private UI and the two independent bots. */
final class TelegramWebhookController
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(int:PLANNER_INTERNAL_PORT)%')] private int $plannerPort,
        #[Autowire('%env(PLANNER_WEBHOOK_SECRET)%')] private string $plannerSecret,
        #[Autowire('%env(int:TRANSLATOR_INTERNAL_PORT)%')] private int $translatorPort,
        #[Autowire('%env(TRANSLATOR_WEBHOOK_SECRET)%')] private string $translatorSecret,
    ) {}

    #[Route('/telegram/webhook', name: 'app_planner_webhook', methods: ['POST'])]
    public function planner(Request $request): Response { return $this->forward($request, $this->plannerPort, $this->plannerSecret); }

    #[Route('/translator/telegram/webhook', name: 'app_translator_webhook', methods: ['POST'])]
    public function translator(Request $request): Response { return $this->forward($request, $this->translatorPort, $this->translatorSecret); }

    private function forward(Request $request, int $port, string $secret): Response
    {
        if ($port < 1024 || $port > 65535 || strlen($secret) < 16) { return new JsonResponse(['error' => 'unavailable'], 503); }
        if (!hash_equals($secret, $request->headers->get('X-Telegram-Bot-Api-Secret-Token', ''))) { return new JsonResponse(['error' => 'unauthorized'], 401); }
        if (!str_starts_with(strtolower($request->headers->get('Content-Type', '')), 'application/json')) { return new JsonResponse(['error' => 'json_required'], 415); }
        if ((int) $request->headers->get('Content-Length', 0) > 1_048_576) { return new JsonResponse(['error' => 'too_large'], 413); }
        $body = $request->getContent();
        if (strlen($body) > 1_048_576) { return new JsonResponse(['error' => 'too_large'], 413); }
        try {
            $upstream = $this->http->request('POST', 'http://127.0.0.1:'.$port.'/telegram/webhook', [
                'headers' => ['Content-Type' => 'application/json', 'X-Telegram-Bot-Api-Secret-Token' => $secret],
                'body' => $body, 'timeout' => 5, 'max_duration' => 8, 'max_redirects' => 0,
            ]);
            $status = $upstream->getStatusCode();
            // Never expose an upstream error page, logs or traceback to the internet.
            $upstream->cancel();
            return $status >= 200 && $status < 300
                ? new JsonResponse(['ok' => true])
                : new JsonResponse(['error' => 'upstream_rejected'], in_array($status, [400, 401, 403, 413, 415, 429], true) ? $status : 503);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            return new JsonResponse(['error' => 'upstream_unavailable'], 503);
        }
    }
}
