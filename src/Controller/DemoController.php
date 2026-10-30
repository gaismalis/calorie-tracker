<?php

namespace App\Controller;

use App\Demo\DemoAccount;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Try the demo" on the landing page: logs the visitor into the shared, read-only demo account (DemoAccount).
 * No CSRF token: the worst a forged request can do is show someone the demo, and the landing page stays sessionless.
 */
class DemoController extends AbstractController
{
    #[Route('/demo', name: 'app_demo', methods: ['POST'])]
    public function start(DemoAccount $demo, Security $security): Response
    {
        $user = $this->getUser();
        if ($user instanceof User && !$user->isDemo()) {
            return $this->redirectToRoute('app_dashboard'); // already logged into their own account
        }

        $security->login($demo->ready(), 'form_login', 'main');

        return $this->redirectToRoute('app_dashboard');
    }

    /** "Sign up" in the demo banner: leaves the demo, then registration (which a logged-in user can't open). */
    #[Route('/demo/sign-up', name: 'app_demo_sign_up', methods: ['GET'])]
    public function signUp(Security $security): Response
    {
        $user = $this->getUser();
        if ($user instanceof User && $user->isDemo()) {
            $security->logout(false);
        }

        return $this->redirectToRoute('app_register');
    }
}
