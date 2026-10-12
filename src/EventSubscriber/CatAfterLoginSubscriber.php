<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Just for fun: right after logging in to the app, the cat comes within a few seconds. The flag is
 * read (and removed) by the first page that shows the cat, see templates/base.html.twig.
 */
final class CatAfterLoginSubscriber implements EventSubscriberInterface
{
    public const SESSION_KEY = 'cat_after_login';

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLogin'];
    }

    public function onLogin(LoginSuccessEvent $event): void
    {
        if ('main' === $event->getFirewallName() && $event->getRequest()->hasSession()) {
            $event->getRequest()->getSession()->set(self::SESSION_KEY, true);
        }
    }
}
