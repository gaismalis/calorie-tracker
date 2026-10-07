<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\OnboardingFormType;
use App\Weight\WeightRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class OnboardingController extends AbstractController
{
    #[Route('/welcome', name: 'app_onboarding')]
    public function welcome(#[CurrentUser] User $user, Request $request, EntityManagerInterface $entityManager, WeightRecorder $weights): Response
    {
        if (!$user->isOnboardingRequired()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(OnboardingFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setOnboardingRequired(false);
            $weights->record($user, $user->today(), (float) $form->get('weightKg')->getData()); // flushes the profile too
            $this->addFlash('success', "All set! Log what you eat with ＋ Meal. Your daily target is ready.");

            return $this->redirectToRoute('app_dashboard');
        }

        if ($form->isSubmitted()) {
            $entityManager->refresh($user); // don't keep invalid values on the logged-in user
        }

        return $this->render('onboarding/welcome.html.twig', ['form' => $form]);
    }
}
