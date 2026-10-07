<?php

namespace App\Controller;

use App\Account\AccountMailer;
use App\Entity\User;
use App\Form\RegistrationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        Security $security,
        AccountMailer $mailer,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()));
            $user->setOnboardingRequired(true);
            $entityManager->persist($user);
            $entityManager->flush();

            $mailer->sendEmailConfirmation($user);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Welcome! We sent a link to %s to confirm your email address.', $user->getEmail()));

            return $security->login($user, 'form_login', 'main');
        }

        return $this->render('registration/register.html.twig', ['registrationForm' => $form]);
    }
}
