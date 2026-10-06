<?php

namespace App\Controller;

use App\Account\AccountMailer;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * "Forgot password?": ask for a link by email, then choose a new password.
 * The request page never reveals whether an email address has an account.
 */
#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    private const SESSION_TOKEN = 'reset_password_token';

    public function __construct(private readonly ResetPasswordHelperInterface $resetPasswordHelper)
    {
    }

    #[Route('', name: 'app_forgot_password')]
    public function request(Request $request, UserRepository $users, AccountMailer $mailer): Response
    {
        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Email()], 'attr' => ['autocomplete' => 'email', 'autofocus' => true]])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $users->findOneBy(['email' => mb_strtolower(trim($form->get('email')->getData()))]);
            if ($user) {
                try {
                    $mailer->sendPasswordReset($user, $this->resetPasswordHelper->generateResetToken($user));
                } catch (ResetPasswordExceptionInterface) {
                    // Throttled (a link was sent recently) or similar: say the same thing as for unknown emails.
                }
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', ['form' => $form]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        return $this->render('reset_password/check_email.html.twig', [
            'lifetimeMinutes' => intdiv($this->resetPasswordHelper->getTokenLifetime(), 60),
        ]);
    }

    /** The link from the email. The token moves to the session and out of the URL, so it can't leak via the Referer header. */
    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
        ?string $token = null,
    ): Response {
        if ($token) {
            $request->getSession()->set(self::SESSION_TOKEN, $token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $request->getSession()->get(self::SESSION_TOKEN);
        if (null === $token) {
            $this->addFlash('error', 'This reset link is not valid. Please ask for a new one.');

            return $this->redirectToRoute('app_forgot_password');
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $request->getSession()->remove(self::SESSION_TOKEN);
            $this->addFlash('error', $translator->trans($e->getReason(), [], 'ResetPasswordBundle').' Please ask for a new link.');

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createFormBuilder()
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password', 'autofocus' => true]],
                'second_options' => ['label' => 'Repeat new password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The two passwords are not the same.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 8, max: 4096)],
            ])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->markVerified(); // the link reached them, so the address works
            $entityManager->flush();
            $request->getSession()->remove(self::SESSION_TOKEN);

            $this->addFlash('success', 'Your password has been changed. You can log in now.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', ['form' => $form]);
    }
}
