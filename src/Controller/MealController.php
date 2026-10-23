<?php

namespace App\Controller;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Estimation\EstimationOutcome;
use App\Meal\MealEstimation;
use App\Ai\Photo;
use App\Nutrition\FoodCategory;
use App\Nutrition\Per100g;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class MealController extends AbstractController
{
    use DayAwareController;

    private const MAX_DESCRIPTION_LENGTH = 1000;
    private const MAX_ITEM_GRAMS = 5000;

    /** The "what did you eat?" form; shown inside the dialog on the main page (Turbo frame), or as a page. */
    #[Route('/meals/new', name: 'app_meal_new', methods: ['GET'])]
    public function new(#[CurrentUser] User $user, Request $request, RecipeRepository $recipes): Response
    {
        $day = $this->parseLocalDate($user, $request->query->getString('date'));
        $isPast = null !== $day && $day < $user->today();

        return $this->render('meal/new.html.twig', ['day' => $isPast ? $day : null, 'recipes' => $recipes->findForUser($user)]);
    }

    #[Route('/meals', name: 'app_meal_create', methods: ['POST'])]
    public function create(#[CurrentUser] User $user, Request $request, MealEstimation $estimation, RecipeRepository $recipes): Response
    {
        if (!$this->isCsrfTokenValid('meal_create', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // The day the form was on, to return there on errors.
        $formDay = $this->parseLocalDate($user, $request->request->getString('date')) ?? $user->today();

        $description = trim($request->request->getString('description'));
        $photo = $this->uploadedPhoto($request);
        if (is_string($photo)) {
            return $this->flashAndGoToDay($user, $formDay, 'error', $photo);
        }
        // A recipe picked as the base: the text is then only what was added (optional).
        $recipe = null;
        if ('' !== $request->request->getString('recipe')) {
            $recipe = $recipes->find($request->request->getInt('recipe'));
            if (null === $recipe || $recipe->getUser() !== $user) {
                throw $this->createNotFoundException();
            }
        }
        if ('' === $description && null === $photo && null === $recipe) {
            return $this->flashAndGoToDay($user, $formDay, 'error', 'Tell me what you ate, or add a photo.');
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            return $this->flashAndGoToDay($user, $formDay, 'error', sprintf('Please keep it under %d characters.', self::MAX_DESCRIPTION_LENGTH));
        }

        $eatenAt = $this->loggedAt($user, $request->request->getString('date'), $request->request->getString('time'));
        if (is_string($eatenAt)) {
            return $this->flashAndGoToDay($user, $formDay, 'error', $eatenAt);
        }

        [$entry, $outcome] = $estimation->logMeal($user, $description, $eatenAt, $photo, $recipe);
        $back = $eatenAt ?? $user->today();

        return match ($outcome) {
            EstimationOutcome::Estimated => $this->redirectToRoute('app_meal_edit', ['id' => $entry->getId(), 'new' => 1]),
            EstimationOutcome::NoFood => $this->flashAndGoToDay($user, $back, 'error', null !== $photo
                ? "I couldn't find any food in that photo. Try another one, or describe the meal."
                : "I couldn't find any food in that. Try e.g. \"2 eggs and a slice of toast\"."),
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
            $submittedPer100g = $request->request->all('per100g');
            $submittedCategories = $request->request->all('category');
            $newCategories = [];
            $newGrams = [];
            $newPer100g = [];
            foreach ($entry->getItems() as $item) {
                $value = self::number($submitted[$item->getId()] ?? null);
                if (null === $value || $value < 0 || $value > self::MAX_ITEM_GRAMS) {
                    $errors[$item->getId()] = sprintf('Enter grams between 0 and %d.', self::MAX_ITEM_GRAMS);
                    continue;
                }
                $newGrams[$item->getId()] = $value;

                if (isset($submittedCategories[$item->getId()])) {
                    $category = FoodCategory::tryFrom((string) $submittedCategories[$item->getId()]);
                    if (null === $category) {
                        $errors[$item->getId()] = 'Choose food, snack or alcohol.';
                        continue;
                    }
                    $newCategories[$item->getId()] = $category;
                }

                // Values per 100 g, e.g. typed from the label. Only sent for items that have them.
                $per100g = $submittedPer100g[$item->getId()] ?? null;
                if (is_array($per100g)) {
                    $parsed = [];
                    foreach (['kcal' => Per100g::MAX_KCAL, 'protein' => Per100g::MAX_MACRO_GRAMS, 'carbs' => Per100g::MAX_MACRO_GRAMS, 'fat' => Per100g::MAX_MACRO_GRAMS] as $field => $max) {
                        $parsed[$field] = self::number($per100g[$field] ?? null);
                        if (null === $parsed[$field] || $parsed[$field] < 0 || $parsed[$field] > $max) {
                            $errors[$item->getId()] = sprintf('Per 100 g: kcal between 0 and %d; protein, carbs and fat between 0 and %d g.', Per100g::MAX_KCAL, Per100g::MAX_MACRO_GRAMS);
                            continue 2;
                        }
                    }
                    $corrected = new Per100g(...array_map(fn (float $v) => round($v, 1), $parsed));
                    if ($corrected != $item->getPer100g()) { // only real corrections; unchanged values stay as they were
                        $newPer100g[$item->getId()] = $corrected;
                    }
                }
            }

            if (!$errors) {
                $entry->setEatenAt($eatenAt);
                foreach ($entry->getItems() as $item) {
                    if (0.0 === $newGrams[$item->getId()]) {
                        $entry->getItems()->removeElement($item);
                    } else {
                        $item->changeGrams($newGrams[$item->getId()]);
                        if (isset($newPer100g[$item->getId()])) {
                            $item->changePer100g($newPer100g[$item->getId()]);
                        }
                        if (isset($newCategories[$item->getId()])) {
                            $item->setCategory($newCategories[$item->getId()]);
                        }
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
            'isNew' => $request->query->getBoolean('new'),
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


    /**
     * The meal photo from the form: null without one, or an error message. Not stored as a file; only its
     * bytes go along with the meal until it's estimated.
     */
    private function uploadedPhoto(Request $request): Photo|string|null
    {
        $file = $request->files->get('photo');
        if (!$file instanceof UploadedFile) {
            return null;
        }
        if (!$file->isValid()) {
            return \in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'That photo is too big. Try a smaller one.'
                : "The photo didn't upload. Please try again.";
        }
        if ($file->getSize() > Photo::MAX_BYTES) {
            return 'That photo is too big. Try a smaller one.';
        }
        $type = $file->getMimeType();
        if (!\in_array($type, Photo::MIME_TYPES, true)) {
            return 'Please use a JPEG, PNG, WebP or HEIC photo.';
        }

        return new Photo(file_get_contents($file->getPathname()), $type);
    }

    /** A submitted number ("12,5" or "12.5"), or null if it isn't one. */
    private static function number(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim(is_scalar($value) ? (string) $value : ''));

        return is_numeric($value) ? (float) $value : null;
    }
}
