<?php

namespace App\Controller;

use App\Energy\EnergyCalculator;
use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\User;
use App\Nutrition\NutritionEstimationException;
use App\Nutrition\NutritionEstimator;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MealController extends AbstractController
{
    private const MAX_DESCRIPTION_LENGTH = 1000;

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(#[CurrentUser] User $user, MealEntryRepository $meals, EnergyCalculator $energy): Response
    {
        $entries = $meals->findForDay($user, $user->today());

        $totals = ['kcal' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0];
        foreach ($entries as $entry) {
            $totals['kcal'] += $entry->getKcal();
            $totals['protein'] += $entry->getProtein();
            $totals['carbs'] += $entry->getCarbs();
            $totals['fat'] += $entry->getFat();
        }

        return $this->render('meal/dashboard.html.twig', [
            'entries' => $entries,
            'totals' => $totals,
            'energy' => $energy->estimate($user),
        ]);
    }

    #[Route('/meals', name: 'app_meal_create', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        Request $request,
        NutritionEstimator $estimator,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): Response {
        if (!$this->isCsrfTokenValid('meal_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $description = trim($request->request->getString('description'));
        if ('' === $description) {
            $this->addFlash('error', 'Tell me what you ate first.');

            return $this->redirectToRoute('app_dashboard');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $this->addFlash('error', sprintf('Please keep it under %d characters.', self::MAX_DESCRIPTION_LENGTH));

            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $estimate = $estimator->estimate($description);
        } catch (NutritionEstimationException $e) {
            $logger->error('Meal estimation failed', ['exception' => $e]);
            $this->addFlash('error', 'Could not estimate that meal right now. Please try again in a moment.');

            return $this->redirectToRoute('app_dashboard');
        }

        if ([] === $estimate->items) {
            $this->addFlash('error', "I couldn't find any food in that. Try e.g. \"2 eggs and a slice of toast\".");

            return $this->redirectToRoute('app_dashboard');
        }

        $entry = new MealEntry($user, $description, new \DateTimeImmutable(), $estimate->estimatedBy);
        foreach ($estimate->items as $item) {
            $entry->addItem(new MealItem(
                $entry, $item->name, $item->grams, $item->kcal, $item->protein, $item->carbs, $item->fat, $item->assumption,
            ));
        }
        $entityManager->persist($entry);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Logged ~%d kcal.', round($entry->getKcal())));

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/meals/{id}/delete', name: 'app_meal_delete', methods: ['POST'])]
    public function delete(
        #[CurrentUser] User $user,
        MealEntry $entry,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        if ($entry->getUser() !== $user) {
            throw $this->createNotFoundException();
        }
        if (!$this->isCsrfTokenValid('meal_delete_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($entry);
        $entityManager->flush();

        return $this->redirectToRoute('app_dashboard');
    }
}
