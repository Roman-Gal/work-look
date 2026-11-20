<?php

namespace App\Agent;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiClient implements ModelClientInterface
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(OPENAI_API_KEY)%')] private string $apiKey,
        #[Autowire('%env(OPENAI_MODEL)%')] private string $model,
    ) {}

    public function isConfigured(): bool { return trim($this->apiKey) !== ''; }

    public function respond(array $input, array $tools, int $maxOutputTokens): array
    {
        if (!$this->isConfigured()) { throw new \RuntimeException('Не налаштовано OPENAI_API_KEY. Додайте його в .env.local на сервері.'); }
        if (strlen(json_encode($input, JSON_THROW_ON_ERROR)) > 120000) { throw new \RuntimeException('Досягнуто ліміт контексту запуску.'); }
        $response = $this->http->request('POST', 'https://api.openai.com/v1/responses', [
            'auth_bearer' => $this->apiKey, 'timeout' => 45, 'max_duration' => 60, 'max_redirects' => 0,
            'json' => ['model' => $this->model, 'input' => $input, 'tools' => $tools,
                'tool_choice' => 'auto', 'parallel_tool_calls' => false, 'store' => false,
                'include' => ['reasoning.encrypted_content'], 'reasoning' => ['effort' => 'low'],
                'max_output_tokens' => min(3500, $maxOutputTokens)],
        ]);
        if ($response->getStatusCode() !== 200) {
            $code = $response->getStatusCode(); $response->cancel();
            throw new \RuntimeException(sprintf('OpenAI повернув HTTP %d. Перевірте ключ, доступ до моделі та API-баланс.', $code));
        }
        $data = $response->toArray(false);
        if (($data['status'] ?? null) !== 'completed' || !is_array($data['output'] ?? null)) {
            throw new \RuntimeException('Модель не завершила відповідь. Спробуйте коротший запит або інший ліміт.');
        }
        return ['output' => $data['output'], 'tokens' => (int) ($data['usage']['total_tokens'] ?? 0)];
    }
}
