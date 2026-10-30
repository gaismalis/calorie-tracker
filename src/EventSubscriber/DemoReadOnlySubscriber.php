<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The demo account is shared, so visitors can look around and open every form, but nothing they submit is
 * saved (and nothing reaches the AI): every POST is turned into "back where you were" with a note to sign up.
 * From the meal/exercise dialog it goes through /frame-exit so the whole page reloads; requests asking for JSON get the note as JSON.
 */
final class DemoReadOnlySubscriber implements EventSubscriberInterface
{
    public const MESSAGE = 'This is the demo, so changes aren\'t saved. Sign up to log your own meals.';
    /** Leaving the demo still works. */
    private const ALLOWED_ROUTES = ['app_logout', 'app_demo', 'app_demo_sign_up'];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['blockChanges', 0]]; // after routing and the firewall
    }

    public function blockChanges(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        if (!$event->isMainRequest() || $request->isMethodSafe() || in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isDemo()) {
            return;
        }

        if ('json' === $request->getPreferredFormat()) { // e.g. the 🎤 in the meal dialog
            $event->setResponse(new JsonResponse(['error' => self::MESSAGE], 403));

            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', self::MESSAGE);
        }
        $back = $this->backTo($request);
        if ('entry-panel' === $request->headers->get('Turbo-Frame')) {
            $back = $this->urls->generate('app_frame_exit', ['to' => $back]);
        }
        $event->setResponse(new RedirectResponse($back, 303));
    }

    /** The page the form was on (same site only), else the main page. */
    private function backTo(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        if (str_starts_with($referer, $request->getSchemeAndHttpHost().'/')) {
            return substr($referer, strlen($request->getSchemeAndHttpHost()));
        }

        return $this->urls->generate('app_dashboard');
    }
}
