<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** New users go to "Tell us about yourself" (/welcome) until they've filled it in. */
final class OnboardingRedirectSubscriber implements EventSubscriberInterface
{
    /** Pages that work before onboarding is done. */
    private const ALLOWED_ROUTES = ['app_onboarding', 'app_logout', 'app_verify_email', 'app_verify_email_resend', 'app_frame_exit'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['redirectToOnboarding', 0]]; // after routing and the firewall
    }

    public function redirectToOnboarding(RequestEvent $event): void
    {
        $route = (string) $event->getRequest()->attributes->get('_route');
        if (!$event->isMainRequest() || !str_starts_with($route, 'app_') || in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $user->isOnboardingRequired() && 'main' === $this->security->getFirewallConfig($event->getRequest())?->getName()) {
            $event->setResponse(new RedirectResponse($this->urls->generate('app_onboarding')));
        }
    }
}
