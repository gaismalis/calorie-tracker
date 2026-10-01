<?php

namespace App\Controller;

use App\Entity\ExerciseEntry;
use App\Entity\User;
use App\Estimation\EstimationOutcome;
use App\Exercise\ExerciseEstimation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ExerciseController extends AbstractController
{
    use DayAwareController;

    private const MAX_DESCRIPTION_LENGTH = 1000;
    private const MAX_ITEM_KCAL = 10000;

    #[Route('/exercises', name: 'app_exercise_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, Request $request, ExerciseEstimation $estimation): Response
    {
        if (!$this->isCsrfTokenValid('exercise_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $formDay = $this->parseLocalDate($user, $request->request->getString('date')) ?? $user->today();

        $description = trim($request->request->getString('description'));
        if ('' === $description) {
            return $this->flashAndGoToDay($user, $formDay, 'error', 'Tell me what you did first.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            return $this->flashAndGoToDay($user, $formDay, 'error', sprintf('Please keep it under %d characters.', self::MAX_DESCRIPTION_LENGTH));
        }

        $performedAt = $this->loggedAt($user, $request->request->getString('date'), $request->request->getString('time'));
        if (is_string($performedAt)) {
            return $this->flashAndGoToDay($user, $formDay, 'error', $performedAt);
        }

        [$entry, $outcome] = $estimation->logExercise($user, $description, $performedAt);
        $back = $performedAt ?? $user->today();

        return match ($outcome) {
            EstimationOutcome::Estimated => $this->flashAndGoToDay($user, $back, 'success', ExerciseEstimation::ESTIMATED_BY_USER === $entry->getEstimatedBy()
                ? sprintf('Logged %d kcal burned, as you entered.', round($entry->getKcal()))
                : sprintf('Logged ~%d kcal burned.', round($entry->getKcal()))),
            EstimationOutcome::NoFood => $this->flashAndGoToDay($user, $back, 'error', "I couldn't find any activity in that. Try e.g. \"30 min running\" or \"workout, burned 400 kcal\"."),
            EstimationOutcome::Retrying => $this->flashAndGoToDay($user, $back, 'info', "Saved. The AI is slow right now, so we'll estimate it in the background. The numbers will appear here shortly."),
            EstimationOutcome::Failed => $this->flashAndGoToDay($user, $back, 'error', 'Saved, but we could not estimate it. You can retry later.'),
        };
    }

    /** Adjust burned calories per activity and when it happened. */
    #[Route('/exercises/{id}/edit', name: 'app_exercise_edit', methods: ['GET', 'POST'])]
    public function edit(#[CurrentUser] User $user, ExerciseEntry $entry, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if ($entry->isPending() || $entry->isFailed()) {
            return $this->flashAndGoToLog('exercise', $user, $entry->getPerformedAt(), 'error', 'This exercise has no estimate yet.');
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('exercise_edit_'.$entry->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $performedAt = $this->parseLocalDateTime($user, $request->request->getString('performed_date'), $request->request->getString('performed_time'));
            if (null === $performedAt) {
                $errors['performedAt'] = 'Enter a valid date and time.';
            } elseif ($performedAt > new \DateTimeImmutable('+5 minutes')) {
                $errors['performedAt'] = "You can't log exercise in the future.";
            }

            $submitted = $request->request->all('kcal');
            $newKcal = [];
            foreach ($entry->getItems() as $item) {
                $value = str_replace(',', '.', trim((string) ($submitted[$item->getId()] ?? '')));
                if (!is_numeric($value) || (float) $value < 0 || (float) $value > self::MAX_ITEM_KCAL) {
                    $errors[$item->getId()] = sprintf('Enter kcal between 0 and %d.', self::MAX_ITEM_KCAL);
                    continue;
                }
                $newKcal[$item->getId()] = (float) $value;
            }

            if (!$errors) {
                $entry->setPerformedAt($performedAt);
                foreach ($entry->getItems() as $item) {
                    if (0.0 === $newKcal[$item->getId()]) {
                        $entry->getItems()->removeElement($item);
                    } else {
                        $item->setKcal($newKcal[$item->getId()]);
                    }
                }

                if ($entry->getItems()->isEmpty()) {
                    $entityManager->remove($entry);
                    $entityManager->flush();

                    return $this->flashAndGoToLog('exercise', $user, $entry->getPerformedAt(), 'success', 'All activities set to 0 kcal, so the entry was removed.');
                }

                $entry->recalculateTotals();
                $entityManager->flush();

                return $this->flashAndGoToLog('exercise', $user, $entry->getPerformedAt(), 'success', sprintf('Saved: ~%d kcal burned.', round($entry->getKcal())));
            }
        }

        return $this->render('exercise/edit.html.twig', [
            'entry' => $entry,
            'errors' => $errors,
            'backUrl' => $this->dayUrl($user, $entry->getPerformedAt(), 'exercise'),
        ], new Response(status: $errors ? 422 : 200));
    }

    #[Route('/exercises/{id}/retry', name: 'app_exercise_retry', methods: ['POST'])]
    public function retry(#[CurrentUser] User $user, ExerciseEntry $entry, Request $request, ExerciseEstimation $estimation): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if (!$this->isCsrfTokenValid('exercise_retry_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $day = $entry->getPerformedAt();
        if (!$estimation->canRetryManually($entry)) {
            return $this->flashAndGoToLog('exercise', $user, $day, 'error', 'You can retry this once per hour.');
        }

        return match ($estimation->retryManually($entry)) {
            EstimationOutcome::Estimated => $this->flashAndGoToLog('exercise', $user, $day, 'success', sprintf('Logged ~%d kcal burned.', round($entry->getKcal()))),
            EstimationOutcome::NoFood => $this->flashAndGoToLog('exercise', $user, $day, 'error', "The AI couldn't find any activity in that. You can delete it and log it again."),
            default => $this->flashAndGoToLog('exercise', $user, $day, 'error', 'Still could not estimate it. You can try again in an hour.'),
        };
    }

    #[Route('/exercises/{id}/delete', name: 'app_exercise_delete', methods: ['POST'])]
    public function delete(#[CurrentUser] User $user, ExerciseEntry $entry, Request $request, EntityManagerInterface $entityManager): Response
    {
        $this->denyUnlessOwner($user, $entry);
        if (!$this->isCsrfTokenValid('exercise_delete_'.$entry->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $day = $entry->getPerformedAt();
        $entityManager->remove($entry);
        $entityManager->flush();

        return $this->redirect($this->dayUrl($user, $day, 'exercise'));
    }
}
