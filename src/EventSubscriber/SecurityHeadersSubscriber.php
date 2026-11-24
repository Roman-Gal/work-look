<?php
namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    // Exact progress-bar stylesheet injected by pinned @hotwired/turbo 8.0.23.
    // Keep arbitrary inline CSS blocked; SecurityHeadersTest checks the vendored CSS.
    private const TURBO_STYLE_HASH = 'sha256-WAyOw4V+FqDc35lQPyRADLBWbuNK8ahvYEaQIYF1+Ps=';

    public static function getSubscribedEvents(): array { return [KernelEvents::REQUEST => ['request', 128], KernelEvents::RESPONSE => 'response']; }
    public function request(RequestEvent $event): void { $event->getRequest()->attributes->set('csp_nonce', base64_encode(random_bytes(18))); }
    public function response(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $response = $event->getResponse(); $nonce = $event->getRequest()->attributes->get('csp_nonce');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        if ($event->getRequest()->isSecure()) { $response->headers->set('Strict-Transport-Security', 'max-age=15552000'); }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $turboStyleHash = self::TURBO_STYLE_HASH;
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' '$turboStyleHash'; img-src 'self' data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/assets/')) { $response->headers->set('Cache-Control', 'private, no-store'); }
    }
}
