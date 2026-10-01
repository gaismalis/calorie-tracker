<?php

namespace App\Controller;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\EstimationOutcome;
use App\Meal\MealEstimation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MealController extends AbstractController
{
    use DayAwareController;

    private const MAX_DESCRIPTION_LENGTH = 1000;
    private const MAX_ITEM_GRAMS = 5000;

    #[Route('/meals', name: 'app_meal_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, Request $request, MealEstimation $estimation): Response
    {
        if (!$this->isCsrfTokenValid('meal_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The day the form was on, to return there on errors.
        $formDay = $this->parseLocalDate($user, $request->request->getString('date')) ?? $user->today();

        $description = trim($request->request->getString('description'));
        if ('' === $description) {
            return $this->flashAndGoToDay($user, $formDay, 'error', 'Tell me what you ate first.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            return $this->flashAndGoToDay($user, $formDay, 'error', sprintf('Please keep it under %d characters.', self::MAX_DESCRIPTION_LENGTH));
        }

        $eatenAt = $this->loggedAt($user, $request->request->getString('date'), $request->request->getString('time'));
        if (is_string($eatenAt)) {
            return $this->flashAndGoToDay($user, $formDay, 'error', $eatenAt);
        }

        [$entry, $outcome] = $estimation->logMeal($user, $description, $eatenAt);
        $back = $eatenAt ?? $user->today();

        return match ($outcome) {
            EstimationOutcome::Estimated => $this->redirectToRoute('app_meal_edit', ['id' => $entry->getId()]),
            EstimationOutcome::NoFood => $this->flashAndGoToDay($user, $back, 'error', "I couldn't find any food in that. Try e.g. \"2 eggs and a slice of toast\"."),
            EstimationOutcome::Retrying => $this->flashAndGoToDay($user, $back, 'info', "Saved. The AI is slow right now, so we'll estimate it in the background. The numbers will appear here shortly."),
            EstimationOutcome::Failed => $this->flashAndGoToDay($user, $back, 'error', 'Saved, but we could not estimate it. You can retry later.'),
        };
    }

    /** Review the AI's suggestion and adjust grams; kcal and macros scale with the amount. */
    #[Route('/meals/{id}/edit', name: 'app_meal_edit', methods: ['GET', 'POST'])]
    public function edit(#[CurrentUser] User $user, MealEntry $entry, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if ($entry->isPending() || $entry->isFailed()) {
            return $this->flashAndGoToLog('food', $user, $entry->getEatenAt(), 'error', 'This meal has no estimate yet.');
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('meal_edit_'.$entry->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $eatenAt = $this->parseLocalDateTime($user, $request->request->getString('eaten_date'), $request->request->getString('eaten_time'));
            if (null === $eatenAt) {
                $errors['eatenAt'] = 'Enter a valid date and time.';
            } elseif ($eatenAt > new \DateTimeImmutable('+5 minutes')) {
                $errors['eatenAt'] = "You can't log meals in the future.";
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
                $entry->setEatenAt($eatenAt);
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

                    return $this->flashAndGoToLog('food', $user, $entry->getEatenAt(), 'success', 'All items set to 0 g, so the meal was removed.');
                }

                $entry->recalculateTotals();
                $entityManager->flush();

                return $this->flashAndGoToLog('food', $user, $entry->getEatenAt(), 'success', sprintf('Logged ~%d kcal.', round($entry->getKcal())));
            }
        }

        return $this->render('meal/edit.html.twig', [
            'entry' => $entry,
            'errors' => $errors,
            'backUrl' => $this->dayUrl($user, $entry->getEatenAt(), 'food'),
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/meals/{id}/retry', name: 'app_meal_retry', methods: ['POST'])]
    public function retry(#[CurrentUser] User $user, MealEntry $entry, Request $request, MealEstimation $estimation): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if (!$this->isCsrfTokenValid('meal_retry_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $day = $entry->getEatenAt();
        if (!$estimation->canRetryManually($entry)) {
            return $this->flashAndGoToLog('food', $user, $day, 'error', 'You can retry this meal once per hour.');
        }

        return match ($estimation->retryManually($entry)) {
            EstimationOutcome::Estimated => $this->redirectToRoute('app_meal_edit', ['id' => $entry->getId()]),
            EstimationOutcome::NoFood => $this->flashAndGoToLog('food', $user, $day, 'error', "The AI couldn't find any food in that meal. You can delete it and log it again."),
            default => $this->flashAndGoToLog('food', $user, $day, 'error', 'Still could not estimate it. You can try again in an hour.'),
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

        $day = $entry->getEatenAt();
        $entityManager->remove($entry);
        $entityManager->flush();

        return $this->redirect($this->dayUrl($user, $day, 'food'));
    }

}
