<?php
declare(strict_types=1);
namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\ActivityRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\{RequestEvent, ResponseEvent};
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\{LoginFailureEvent, LoginSuccessEvent, LogoutEvent};

final class ActivitySubscriber implements EventSubscriberInterface
{
    public function __construct(private ActivityRecorder $recorder, private TokenStorageInterface $tokens) {}
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['start', 512], KernelEvents::RESPONSE => ['finish', -255],
            LoginSuccessEvent::class => 'login', LoginFailureEvent::class => 'failure', LogoutEvent::class => 'logout'];
    }
    public function start(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $event->getRequest()->attributes->set('_activity_start', microtime(true));
        $event->getRequest()->attributes->set('_activity_id', bin2hex(random_bytes(16)));
    }
    public function login(LoginSuccessEvent $event): void { $event->getRequest()->attributes->set('_activity_auth', 'login_success'); }
    public function failure(LoginFailureEvent $event): void { $event->getRequest()->attributes->set('_activity_auth', 'login_failure'); }
    public function logout(LogoutEvent $event): void
    {
        $event->getRequest()->attributes->set('_activity_auth', 'logout');
        $event->getRequest()->attributes->set('_activity_user', $event->getToken()?->getUser());
    }
    public function finish(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $request = $event->getRequest(); $path = $request->getPathInfo();
        if (preg_match('~^/(assets/|_wdt/|_profiler/|healthz$|favicon\.|telegram/webhook$|translator/telegram/webhook$)~', $path) || $request->headers->has('Turbo-Frame')) { return; }
        $user = $request->attributes->get('_activity_user') ?? $this->tokens->getToken()?->getUser();
        $status = $event->getResponse()->getStatusCode();
        $isPage = $request->isMethod('GET') && $status === 200 && !str_starts_with((string) $request->attributes->get('_route'), 'app_admin')
            && str_contains($event->getResponse()->headers->get('Content-Type', ''), 'text/html');
        $kind = $request->attributes->get('_activity_auth') ?? ($isPage ? 'page_view' : 'http_request');
        $this->recorder->record($request, $kind, $status, $user instanceof User ? $user : null);
    }
}
