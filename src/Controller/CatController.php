<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** The toggle above the 🐾 button: switches the cat off (no visits, no 🐾) or back on, for all of the user's devices.
 * Not under /cat/: that's public/cat/ (the sprite), which the web server serves as static files. */
class CatController extends AbstractController
{
    #[Route('/settings/cat', name: 'app_cat_toggle', methods: ['POST'])]
    public function toggle(#[CurrentUser] User $user, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('cat_toggle', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $user->setCatEnabled(!$user->isCatEnabled());
        $em->flush();

        // Back to the page (and day) the toggle was on; only paths on this site.
        $back = $request->request->getString('back');

        return $this->redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : $this->generateUrl('app_dashboard'), 303);
    }
}
