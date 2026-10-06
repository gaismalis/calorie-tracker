<?php

namespace App\Controller;

use App\Account\AccountMailer;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class VerifyEmailController extends AbstractController
{
    /** The link from the confirmation email. Works without being logged in (e.g. opened on another device). */
    #[Route('/verify/email', name: 'app_verify_email', methods: ['GET'])]
    public function verify(
        Request $request,
        UserRepository $users,
        VerifyEmailHelperInterface $helper,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $user = $users->find($request->query->getInt('id'));
        $next = $this->getUser() ? 'app_dashboard' : 'app_login';
        if (!$user) {
            $this->addFlash('error', 'This confirmation link is not valid.');

            return $this->redirectToRoute($next);
        }

        try {
            $helper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), $user->getEmail());
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', $translator->trans($e->getReason(), [], 'VerifyEmailBundle').' You can ask for a new link after logging in.');

            return $this->redirectToRoute($next);
        }

        $user->markVerified();
        $entityManager->flush();
        $this->addFlash('success', 'Thanks, your email address is confirmed.');

        return $this->redirectToRoute($next);
    }

    #[Route('/verify/email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    public function resend(#[CurrentUser] User $user, Request $request, AccountMailer $mailer, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('verify_email_resend', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->isVerified()) {
            $this->addFlash('success', 'Your email address is already confirmed.');
        } elseif (!$mailer->canResendConfirmation($user)) {
            $this->addFlash('error', 'We just sent you a link. Please wait a minute before asking for another one.');
        } else {
            $mailer->sendEmailConfirmation($user);
            $entityManager->flush();
            $this->addFlash('success', sprintf('We sent a new confirmation link to %s.', $user->getEmail()));
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
