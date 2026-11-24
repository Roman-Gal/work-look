<?php
namespace App\Tests;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SecurityHeadersTest extends TestCase
{
    private function response(): Response
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('https://worklook.test/login');
        $subscriber = new SecurityHeadersSubscriber();
        $subscriber->request(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new Response();
        $subscriber->response(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        return $response;
    }

    public function testPolicyAllowsOnlyThePinnedTurboInlineStylesheet(): void
    {
        $js = file_get_contents(__DIR__.'/../assets/vendor/@hotwired/turbo/turbo.index.js');
        // Extract the actual pinned module's CSS template; changes in Turbo fail closed.
        self::assertSame(1, preg_match('/static get defaultCSS\(\)\{return \w+`([^`]+)`\}/s', $js, $matches), 'Recheck the Turbo CSP hash after upgrading the module.');
        self::assertSame(1, preg_match('/static animationDuration=(\d+);/', $js, $duration));
        $css = str_replace(["\r\n", "\r"], "\n", $matches[1]);
        $css = preg_replace_callback('/\$\{\w+\.animationDuration(\/2)?\}/', static fn ($m) => (string) ((int) $duration[1] / (isset($m[1]) ? 2 : 1)), $css);
        self::assertStringNotContainsString('${', $css, 'Handle new Turbo template expressions explicitly.');
        // Match Turbo's unindent() including its trailing whitespace/newline.
        $lines = explode("\n", preg_replace('/^\n/', '', $css));
        preg_match('/^\s+/', $lines[0], $indent);
        $css = implode("\n", array_map(static fn ($line) => substr($line, strlen($indent[0] ?? '')), $lines));
        $hash = 'sha256-'.base64_encode(hash('sha256', $css, true));
        $policy = $this->response()->headers->get('Content-Security-Policy');
        self::assertStringContainsString("style-src 'self' '$hash';", $policy);
        self::assertStringNotContainsString("'unsafe-inline'", $policy);
        self::assertStringNotContainsString("'unsafe-eval'", $policy);
        self::assertStringNotContainsString('sha256-'.base64_encode(hash('sha256', $css.'body { display: none; }', true)), $policy);
    }

    public function testScriptNoncesStillRotateAndResponsesArePrivate(): void
    {
        $first = $this->response();
        $second = $this->response();
        foreach ([$first, $second] as $response) {
            self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+';/", $response->headers->get('Content-Security-Policy'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        }
        self::assertNotSame($first->headers->get('Content-Security-Policy'), $second->headers->get('Content-Security-Policy'));
    }
}
