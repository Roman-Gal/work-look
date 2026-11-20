<?php
namespace App\Tests;

use App\Controller\TelegramWebhookController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\{MockHttpClient, Response\MockResponse};
use Symfony\Component\HttpFoundation\Request;

final class TelegramWebhookTest extends TestCase
{
    private const SECRET = 'test-only-telegram-secret-123456789';
    public function testValidUpdateUsesFixedLoopbackTargetAndDoesNotForwardCookies(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method); self::assertSame('http://127.0.0.1:18080/telegram/webhook', $url);
            self::assertSame('{"update_id":123}', $options['body']);
            self::assertSame(0, $options['max_redirects']);
            self::assertArrayNotHasKey('cookie', $options['normalized_headers']);
            return new MockResponse('{"ok":true}');
        });
        $controller = new TelegramWebhookController($http, 18080, self::SECRET, 18081, self::SECRET);
        $request = Request::create('/telegram/webhook', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET, 'HTTP_COOKIE' => 'session=private'], content: '{"update_id":123}');
        self::assertSame(200, $controller->planner($request)->getStatusCode());
    }
    public function testInvalidSecretAndOversizedBodyNeverReachBot(): void
    {
        $http = new MockHttpClient(function () { self::fail('Invalid update must not be forwarded.'); });
        $controller = new TelegramWebhookController($http, 18080, self::SECRET, 18081, self::SECRET);
        self::assertSame(401, $controller->planner(Request::create('/', 'POST'))->getStatusCode());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET], content: str_repeat('x', 1_048_577));
        self::assertSame(413, $controller->planner($request)->getStatusCode());
    }
    public function testDisabledConfigurationAndUpstreamFailureAreNotAcknowledged(): void
    {
        $http = new MockHttpClient(new MockResponse('private traceback must not leak', ['http_code' => 500]));
        $controller = new TelegramWebhookController($http, 0, '', 18081, self::SECRET);
        self::assertSame(503, $controller->planner(Request::create('/', 'POST'))->getStatusCode());
        $request = Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET], content: '{}');
        $response = $controller->translator($request);
        self::assertSame(503, $response->getStatusCode()); self::assertStringNotContainsString('private traceback', $response->getContent());
    }
}
