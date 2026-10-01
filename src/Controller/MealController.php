<?php

namespace App\Controller;

use App\Energy\EnergyCalculator;
use App\Entity\MealEntry;
use App\Entity\User;
use App\Meal\EstimationOutcome;
use App\Meal\MealEstimation;
use App\Repository\MealEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MealController extends AbstractController
{
    private const MAX_DESCRIPTION_LENGTH = 1000;
    private const MAX_ITEM_GRAMS = 5000;

    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function dashboard(
        #[CurrentUser] User $user,
        MealEntryRepository $meals,
        EnergyCalculator $energy,
        MealEstimation $estimation,
    ): Response {
        $entries = $meals->findForDay($user, $user->today());

        $totals = ['kcal' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0];
        $retryAvailableAt = [];
        foreach ($entries as $entry) {
            $totals['kcal'] += $entry->getKcal();
            $totals['protein'] += $entry->getProtein();
            $totals['carbs'] += $entry->getCarbs();
            $totals['fat'] += $entry->getFat();
            if ($entry->isFailed()) {
                $retryAvailableAt[$entry->getId()] = $estimation->canRetryManually($entry) ? null : $estimation->manualRetryAvailableAt($entry);
            }
        }

        return $this->render('meal/dashboard.html.twig', [
            'entries' => $entries,
            'totals' => $totals,
            'energy' => $energy->estimate($user),
            'retryAvailableAt' => $retryAvailableAt,
            'hasPending' => array_any($entries, fn (MealEntry $e) => $e->isPending()),
        ]);
    }

    #[Route('/meals', name: 'app_meal_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, Request $request, MealEstimation $estimation): Response
    {
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

        [$entry, $outcome] = $estimation->logMeal($user, $description);

        return match ($outcome) {
            EstimationOutcome::Estimated => $this->redirectToRoute('app_meal_edit', ['id' => $entry->getId()]),
            EstimationOutcome::NoFood => $this->flashAndGoHome('error', "I couldn't find any food in that. Try e.g. \"2 eggs and a slice of toast\"."),
            EstimationOutcome::Retrying => $this->flashAndGoHome('info', "Saved. The AI is slow right now, so we'll estimate it in the background. The numbers will appear here shortly."),
            EstimationOutcome::Failed => $this->flashAndGoHome('error', 'Saved, but we could not estimate it. You can retry later.'),
        };
    }

    /** Review the AI's suggestion and adjust grams; kcal and macros scale with the amount. */
    #[Route('/meals/{id}/edit', name: 'app_meal_edit', methods: ['GET', 'POST'])]
    public function edit(#[CurrentUser] User $user, MealEntry $entry, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if ($entry->isPending() || $entry->isFailed()) {
            return $this->flashAndGoHome('error', 'This meal has no estimate yet.');
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('meal_edit_'.$entry->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $submitted = $request->request->all('grams');
            $newGrams = [];
            foreach ($entry->getItems() as $item) {
                $value = str_replace(',', '.', trim((string) ($submitted[$item->getId()] ?? '')));
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_ITEM_GRAMS) {
                    $errors[$item->getId()] = sprintf('Enter grams between 0 and %d.', self::MAX_ITEM_GRAMS);
                    continue;
                }
                $newGrams[$item->getId()] = (float) $value;
            }

            if (!$errors) {
                foreach ($entry->getItems() as $item) {
                    if (0.0 === $newGrams[$item->getId()]) {
                        $entry->getItems()->removeElement($item);
                    } else {
                        $item->changeGrams($newGrams[$item->getId()]);
                    }
                }

                if ($entry->getItems()->isEmpty()) {
                    $entityManager->remove($entry);
                    $entityManager->flush();

                    return $this->flashAndGoHome('success', 'All items set to 0 g, so the meal was removed.');
                }

                $entry->recalculateTotals();
                $entityManager->flush();

                return $this->flashAndGoHome('success', sprintf('Logged ~%d kcal.', round($entry->getKcal())));
            }
        }

        return $this->render('meal/edit.html.twig', ['entry' => $entry, 'errors' => $errors], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/meals/{id}/retry', name: 'app_meal_retry', methods: ['POST'])]
    public function retry(#[CurrentUser] User $user, MealEntry $entry, Request $request, MealEstimation $estimation): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if (!$this->isCsrfTokenValid('meal_retry_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!$estimation->canRetryManually($entry)) {
            return $this->flashAndGoHome('error', 'You can retry this meal once per hour.');
        }

        return match ($estimation->retryManually($entry)) {
            EstimationOutcome::Estimated => $this->redirectToRoute('app_meal_edit', ['id' => $entry->getId()]),
            EstimationOutcome::NoFood => $this->flashAndGoHome('error', "The AI couldn't find any food in that meal. You can delete it and log it again."),
            default => $this->flashAndGoHome('error', 'Still could not estimate it. You can try again in an hour.'),
        };
    }

    #[Route('/meals/{id}/delete', name: 'app_meal_delete', methods: ['POST'])]
    public function delete(
        #[CurrentUser] User $user,
        MealEntry $entry,
        Request $request,
        EntityManagerInterface $entityManager,
    ): Response {
        $this->denyUnlessOwner($user, $entry);
        if (!$this->isCsrfTokenValid('meal_delete_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $entityManager->remove($entry);
        $entityManager->flush();

        return $this->redirectToRoute('app_dashboard');
    }

    private function denyUnlessOwner(User $user, MealEntry $entry): void
    {
        if ($entry->getUser() !== $user) {
            throw $this->createNotFoundException();
        }
    }

    private function flashAndGoHome(string $type, string $message): Response
    {
        $this->addFlash($type, $message);

        return $this->redirectToRoute('app_dashboard');
    }
}
