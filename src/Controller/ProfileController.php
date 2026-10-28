<?php

namespace App\Controller;

use App\Entity\User;
use App\Export\FoodExport;
use App\Form\ProfileFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function edit(#[CurrentUser] User $user, Request $request, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Profile saved.');

            return $this->redirectToRoute('app_dashboard');
        }

        if ($form->isSubmitted()) {
            // Don't let invalid values linger on the logged-in user for the rest of the request.
            $entityManager->refresh($user);
        }

        return $this->render('profile/edit.html.twig', ['form' => $form]);
    }

    /** Everything eaten, as a JSON file: {"2026-10-27": [{"food": "Greek yogurt", "kcal": 146, ...}, ...], ...}. */
    #[Route('/profile/export', name: 'app_profile_export', methods: ['GET'])]
    public function export(#[CurrentUser] User $user, FoodExport $export): Response
    {
        $response = new JsonResponse($export->forUser($user) ?: new \stdClass()); // {} rather than [] when nothing is logged
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('calorie-tracker-foods-%s.json', $user->today()->format('Y-m-d')),
        ));

        return $response;
    }
}
