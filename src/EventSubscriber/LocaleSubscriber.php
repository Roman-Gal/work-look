<?php
namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    public const LOCALES = ['uk', 'pl', 'en'];
    public const COOKIE = 'worklook_locale';

    public static function getSubscribedEvents(): array
    {
        // After routing (32), before Symfony's LocaleListener (16) and firewall (8).
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) { return; }
        $locale = $event->getRequest()->cookies->get(self::COOKIE);
        $event->getRequest()->setLocale(in_array($locale, self::LOCALES, true) ? $locale : 'uk');
    }
}
