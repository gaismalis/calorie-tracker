<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\Recipe;
use App\Entity\User;
use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\MealEstimate;
use App\Recipe\RecipeItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** "My recipes": meals with the user's own numbers, logged without the AI. */
class RecipeTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
    }

    public function testTheRecipesPageIsInTheMenuAndListsRecipesByName(): void
    {
        $this->storeRecipe('Protein shake', [new RecipeItem('Whey', 30, 120, 24, 2, 1.5)]);
        $this->storeRecipe('coffee with milk', [new RecipeItem('Coffee', 200, 4, 0.3, 0, 0), new RecipeItem('Milk', 50, 23, 1.7, 2.4, 0.8)]);

        $crawler = $this->client->request('GET', '/');
        self::assertSame('/recipes', $crawler->filter('.topbar nav a:contains("Recipes")')->attr('href'));

        $crawler = $this->client->request('GET', '/recipes');
        self::assertSame(['coffee with milk', 'Protein shake'], $crawler->filter('.recipe .recipe-title')->each(fn ($n) => $n->text()));
        self::assertSame('27 kcal', $crawler->filter('.recipe .kcal')->first()->text());
        self::assertStringContainsString('Coffee 200 g, Milk 50 g', $crawler->filter('.recipe-macros')->first()->text());
        self::assertSame('entry-dialog#open', $crawler->filter('a:contains("New recipe")')->attr('data-action'), 'the form opens in a popup');
    }

    public function testCreateARecipe(): void
    {
        $crawler = $this->client->request('GET', '/recipes/new');
        self::assertCount(1, $crawler->filter('turbo-frame#entry-panel form.recipe-form .recipe-items .recipe-item'), 'one empty food to start');

        $this->client->request('POST', '/recipes/new', [
            'name' => 'Greek yogurt bowl',
            'items' => [
                ['name' => 'Greek yogurt 2%', 'grams' => '200', 'kcal' => '146', 'protein' => '20', 'carbs' => '8', 'fat' => '4', 'category' => 'food'],
                ['name' => 'Honey', 'grams' => '15', 'kcal' => '46', 'protein' => '0', 'carbs' => '12.4', 'fat' => '0', 'category' => 'snack'],
            ],
            '_token' => $this->token($crawler),
        ]);

        self::assertResponseRedirects('/recipes');
        $recipe = $this->em()->getRepository(Recipe::class)->findOneBy(['name' => 'Greek yogurt bowl']);
        self::assertSame(192.0, $recipe->getKcal());
        self::assertSame(FoodCategory::Snack, $recipe->getItems()[1]->category);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved “Greek yogurt bowl” to My recipes.');
    }

    public function testErrorsAreShownNextToTheFoodAndTheInputIsKept(): void
    {
        $crawler = $this->client->request('GET', '/recipes/new');

        $crawler = $this->client->request('POST', '/recipes/new', [
            'name' => 'Shake',
            'items' => [4 => ['name' => 'Whey', 'grams' => '3', 'kcal' => '120', 'protein' => '24', 'carbs' => '2', 'fat' => '1.5', 'category' => 'food']],
            '_token' => $this->token($crawler),
        ]);

        self::assertResponseStatusCodeSame(422);
        $item = $crawler->filter('.recipe-item')->first();
        self::assertStringContainsString("120 kcal in 3 g isn't possible", $item->text());
        self::assertSame('Whey', $item->filter('input[name="items[4][name]"]')->attr('value'));
        self::assertSame(0, $this->em()->getRepository(Recipe::class)->count([]));
    }

    public function testSaveALoggedMealAsARecipe(): void
    {
        $meal = $this->storeMeal();

        $crawler = $this->client->request('GET', '/');
        $bookmark = $crawler->filter('#panel-food a.bookmark');
        self::assertSame('/recipes/new?fromMeal='.$meal->getId(), $bookmark->attr('href'));
        self::assertSame('entry-dialog#open:prevent', $bookmark->attr('data-action'), 'opens in the popup');

        $crawler = $this->client->request('GET', '/recipes/new?fromMeal='.$meal->getId());
        self::assertSame('Save as a recipe', $crawler->filter('h2')->text());
        self::assertSame('Yogurt, Honey', $crawler->filter('input[name=name]')->attr('value'));
        self::assertSame(['Yogurt', 'Honey'], $crawler->filter('.recipe-items input[name$="[name]"]')->reduce(fn ($n) => 'name' !== $n->attr('name'))->each(fn ($n) => $n->attr('value')));
        self::assertSame('146', $crawler->filter('input[name="items[0][kcal]"]')->attr('value'));
        self::assertSame('snack', $crawler->filter('select[name="items[1][category]"] option[selected]')->attr('value'));

        $form = $crawler->filter('form.recipe-form')->form();
        $form['name'] = 'My yogurt';
        $form['items[0][kcal]'] = '150';
        $this->client->submit($form);

        self::assertResponseRedirects('/?log=food', null, 'back to the meal\'s day');
        self::assertSame(196.0, $this->em()->getRepository(Recipe::class)->findOneBy(['name' => 'My yogurt'])->getKcal());
    }

    public function testLogARecipeFromTheMealDialogWithoutTheAi(): void
    {
        $recipe = $this->storeRecipe('Protein shake', [new RecipeItem('Whey', 30, 120, 24, 2, 1.5), new RecipeItem('Milk', 250, 115, 8.5, 12, 3.8)]);

        $crawler = $this->client->request('GET', '/meals/new');
        $pick = $crawler->filter('button.recipe-pick');
        self::assertStringContainsString('Protein shake', $pick->text());
        self::assertStringContainsString('235 kcal', $pick->text());
        self::assertSame('/recipes/'.$recipe->getId().'/log', $pick->attr('formaction'), 'part of the meal form: its time goes along');

        $this->client->request('POST', '/recipes/'.$recipe->getId().'/log', ['date' => $this->user->today()->modify('-1 day')->format('Y-m-d'), 'time' => '08:15', '_token' => $crawler->filter('form.meal-form input[name=_token]')->attr('value')]);

        $meal = $this->em()->getRepository(MealEntry::class)->findOneBy([]);
        self::assertResponseRedirects('/meals/'.$meal->getId().'/edit?new=1', null, 'the usual review, to adjust the amount');
        self::assertSame('recipe', $meal->getEstimatedBy());
        self::assertSame('Protein shake', $meal->getRawText());
        self::assertSame(235.0, $meal->getKcal());
        self::assertSame($this->user->today()->modify('-1 day')->format('Y-m-d').' 08:15', $meal->getEatenAt()->setTimezone($this->user->getDateTimeZone())->format('Y-m-d H:i'), 'the day and time of the meal form');
        self::assertSame(400.0, $meal->getItems()->first()->getPer100g()->kcal);

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('From your recipe:', $crawler->filter('h2')->text());
        $crawler = $this->client->request('GET', '/day/'.$this->user->today()->modify('-1 day')->format('Y-m-d').'?log=food');
        self::assertCount(1, $crawler->filter('#panel-food .bookmark.saved'), 'marked as from My recipes, nothing to save');
        self::assertCount(0, $crawler->filter('#panel-food a.bookmark'));
    }

    public function testNoRecipesNoPicks(): void
    {
        $crawler = $this->client->request('GET', '/meals/new');

        self::assertCount(0, $crawler->filter('.recipe-picks'));
    }

    public function testEditAndDelete(): void
    {
        $recipe = $this->storeRecipe('Coffee', [new RecipeItem('Coffee', 200, 4, 0.3, 0, 0)]);

        $crawler = $this->client->request('GET', '/recipes/'.$recipe->getId().'/edit');
        self::assertSame('Edit recipe', $crawler->filter('h2')->text());
        $form = $crawler->filter('form.recipe-form')->form();
        $form['name'] = 'Flat white';
        $this->client->submit($form);
        self::assertResponseRedirects('/recipes');
        self::assertSame('Flat white', $this->em()->getRepository(Recipe::class)->find($recipe->getId())->getName());

        $crawler = $this->client->request('GET', '/recipes');
        $this->client->submit($crawler->filter('.recipe form')->form());
        self::assertResponseRedirects('/recipes');
        self::assertSame(0, $this->em()->getRepository(Recipe::class)->count([]));
    }

    public function testOtherPeoplesRecipesAndMealsAreNotFound(): void
    {
        $other = (new User())->setEmail('other@example.com')->setPassword('x');
        $this->em()->persist($other);
        $recipe = new Recipe($other, 'Theirs', [new RecipeItem('Coffee', 200, 4, 0.3, 0, 0)]);
        $this->em()->persist($recipe);
        $meal = new MealEntry($other, 'theirs', new \DateTimeImmutable());
        $meal->applyEstimate(new MealEstimate([new EstimatedItem('Toast', 50, 130, 4, 24, 2)], 'test'), new \DateTimeImmutable());
        $this->em()->persist($meal);
        $this->em()->flush();

        $this->client->request('GET', '/recipes/'.$recipe->getId().'/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/recipes/new?fromMeal='.$meal->getId());
        self::assertResponseStatusCodeSame(404);
        $crawler = $this->client->request('GET', '/meals/new');
        $this->client->request('POST', '/recipes/'.$recipe->getId().'/log', ['_token' => $crawler->filter('form.meal-form input[name=_token]')->attr('value')]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Theirs', $this->client->request('GET', '/recipes')->text());
    }

    public function testAtMost100Recipes(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $this->em()->persist(new Recipe($this->user, "Recipe $i", [new RecipeItem('Coffee', 200, 4, 0.3, 0, 0)]));
        }
        $this->em()->flush();
        $crawler = $this->client->request('GET', '/recipes/new');

        $this->client->request('POST', '/recipes/new', ['name' => 'One more', 'items' => [['name' => 'Tea', 'grams' => '200', 'kcal' => '2', 'protein' => '0', 'carbs' => '0', 'fat' => '0']], '_token' => $this->token($crawler)]);

        self::assertResponseRedirects('/recipes');
        self::assertSame(100, $this->em()->getRepository(Recipe::class)->count([]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'You can have up to 100 recipes.');
    }

    public function testATokenIsNeeded(): void
    {
        $this->client->request('POST', '/recipes/new', ['name' => 'X', 'items' => []]);

        self::assertResponseStatusCodeSame(403);
    }

    private function token($crawler): string
    {
        return $crawler->filter('form.recipe-form input[name=_token]')->attr('value');
    }

    /** @param list<RecipeItem> $items */
    private function storeRecipe(string $name, array $items): Recipe
    {
        $recipe = new Recipe($this->user, $name, $items);
        $this->em()->persist($recipe);
        $this->em()->flush();

        return $recipe;
    }

    private function storeMeal(): MealEntry
    {
        $meal = new MealEntry($this->user, 'yogurt with honey', new \DateTimeImmutable());
        $meal->applyEstimate(new MealEstimate([
            new EstimatedItem('Yogurt', 200, 146, 20, 8, 4),
            new EstimatedItem('Honey', 15, 46, 0, 12.4, 0, category: FoodCategory::Snack),
        ], 'test'), new \DateTimeImmutable());
        $this->em()->persist($meal);
        $this->em()->flush();

        return $meal;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
