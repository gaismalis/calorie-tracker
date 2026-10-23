<?php

namespace App\Controller;

use App\Entity\MealEntry;
use App\Entity\Recipe;
use App\Entity\User;
use App\Estimation\EstimationStatus;
use App\Recipe\RecipeInput;
use App\Recipe\RecipeItem;
use App\Repository\RecipeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "My recipes": meals the user eats often, with their own exact numbers. Created on the recipes page or
 * from a logged meal (🔖 in the day's log), and picked in the meal dialog as the base of a meal (see MealEstimation::logMeal()).
 * The form opens in the same dialog as meals (Turbo frame "entry-panel").
 */
class RecipeController extends AbstractController
{
    use DayAwareController;

    public const MAX_RECIPES = 100;

    #[Route('/recipes', name: 'app_recipes', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, RecipeRepository $recipes): Response
    {
        return $this->render('recipe/index.html.twig', ['recipes' => $recipes->findForUser($user)]);
    }

    /** A new recipe; with ?fromMeal={id}, filled in from that logged meal so only the numbers need checking. */
    #[Route('/recipes/new', name: 'app_recipe_new', methods: ['GET', 'POST'])]
    public function new(#[CurrentUser] User $user, Request $request, RecipeRepository $recipes, EntityManagerInterface $em): Response
    {
        $meal = null;
        if ($request->query->has('fromMeal')) {
            $meal = $em->find(MealEntry::class, $request->query->getInt('fromMeal'));
            if (null === $meal || $meal->getUser() !== $user || EstimationStatus::Estimated !== $meal->getStatus()) {
                throw $this->createNotFoundException();
            }
        }

        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'recipe_new');
            if ($recipes->countForUser($user) >= self::MAX_RECIPES) {
                return $this->goTo($this->flashAndUrl('error', sprintf('You can have up to %d recipes. Delete one you no longer use first.', self::MAX_RECIPES)));
            }
            $input = RecipeInput::parse($request->request->getString('name'), $request->request->all('items'));
            if ($input->isValid()) {
                $em->persist(new Recipe($user, $input->name, $input->items));
                $em->flush();
                $this->addFlash('success', sprintf('Saved “%s” to My recipes.', $input->name));

                return $this->goTo(null !== $meal ? $this->dayUrl($user, $meal->getEatenAt(), 'food') : $this->generateUrl('app_recipes'));
            }

            return $this->renderForm(null, $request->request->getString('name'), $this->submittedRows($request), $input->errors, $meal);
        }

        if (null !== $meal) {
            $rows = array_map(fn ($item) => $this->row(new RecipeItem(
                $item->getName(), round($item->getGrams(), 1), round($item->getKcal(), 1),
                round($item->getProtein(), 1), round($item->getCarbs(), 1), round($item->getFat(), 1), $item->getCategory(),
            )), $meal->getItems()->toArray());

            return $this->renderForm(null, $meal->getTitle() ?? $meal->getRawText(), array_values($rows), [], $meal);
        }

        return $this->renderForm(null, '', [], [], null);
    }

    #[Route('/recipes/{id}/edit', name: 'app_recipe_edit', methods: ['GET', 'POST'])]
    public function edit(#[CurrentUser] User $user, Recipe $recipe, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessRecipeOwner($user, $recipe);

        if ($request->isMethod('POST')) {
            $this->checkToken($request, 'recipe_edit_'.$recipe->getId());
            $input = RecipeInput::parse($request->request->getString('name'), $request->request->all('items'));
            if ($input->isValid()) {
                $recipe->update($input->name, $input->items);
                $em->flush();

                return $this->goTo($this->flashAndUrl('success', sprintf('Saved “%s”.', $input->name)));
            }

            return $this->renderForm($recipe, $request->request->getString('name'), $this->submittedRows($request), $input->errors, null);
        }

        return $this->renderForm($recipe, $recipe->getName(), array_map(fn (RecipeItem $item) => $this->row($item), $recipe->getItems()), [], null);
    }

    #[Route('/recipes/{id}/delete', name: 'app_recipe_delete', methods: ['POST'])]
    public function delete(#[CurrentUser] User $user, Recipe $recipe, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessRecipeOwner($user, $recipe);
        $this->checkToken($request, 'recipe_delete_'.$recipe->getId());

        $em->remove($recipe);
        $em->flush();

        return $this->redirectToRoute('app_recipes');
    }

    /** @param list<array<string, string>> $rows */
    private function renderForm(?Recipe $recipe, string $name, array $rows, array $errors, ?MealEntry $meal): Response
    {
        return $this->render('recipe/form.html.twig', [
            'recipe' => $recipe,
            'name' => $name,
            'rows' => [] === $rows ? [$this->row(null)] : $rows,
            'errors' => $errors,
            'meal' => $meal,
        ], new Response(status: $errors ? 422 : 200));
    }

    /** @return array<string, string> one row of the form, as the inputs show it */
    private function row(?RecipeItem $item): array
    {
        if (null === $item) {
            return ['name' => '', 'grams' => '', 'kcal' => '', 'protein' => '', 'carbs' => '', 'fat' => '', 'category' => 'food'];
        }
        $number = fn (float $value) => rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return [
            'name' => $item->name, 'grams' => $number($item->grams), 'kcal' => $number($item->kcal),
            'protein' => $number($item->protein), 'carbs' => $number($item->carbs), 'fat' => $number($item->fat),
            'category' => $item->category->value,
        ];
    }

    /** @return list<array<string, string>> the rows as submitted, to show them again with the errors */
    private function submittedRows(Request $request): array
    {
        $rows = [];
        foreach ($request->request->all('items') as $index => $row) {
            if (is_array($row)) {
                $rows[$index] = array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $row) + $this->row(null);
            }
        }

        return $rows;
    }

    private function flashAndUrl(string $type, string $message): string
    {
        $this->addFlash($type, $message);

        return $this->generateUrl('app_recipes');
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function denyUnlessRecipeOwner(User $user, Recipe $recipe): void
    {
        if ($recipe->getUser() !== $user) {
            throw $this->createNotFoundException();
        }
    }
}
