<?php

namespace App\Tests\Integration;

use App\Entity\MealEntry;
use App\Entity\MealItem;
use App\Entity\User;
use App\Nutrition\FoodCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CategorizeMealItemsCommandTest extends KernelTestCase
{
    public function testBackfillsOnlyItemsWithoutCategoryAndCanRunAgain(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('old@example.com')->setPassword('x');
        $meal = new MealEntry($user, 'old meal', new \DateTimeImmutable());
        $items = [
            'beer' => new MealItem($meal, 'Aldaris alus', 500, 215, 2, 18, 0),
            'beer again' => new MealItem($meal, 'Aldaris alus', 330, 142, 1, 12, 0),
            'cake' => new MealItem($meal, 'Medus kūka', 120, 450, 5, 55, 22),
            'soup' => new MealItem($meal, 'Mushroom soup', 300, 180, 4, 15, 10),
            'corrected' => new MealItem($meal, 'Tiramisu', 150, 420, 6, 40, 25, category: FoodCategory::Food), // the user said so
        ];
        foreach ($items as $item) {
            $meal->addItem($item);
        }
        $em->persist($user);
        $em->persist($meal);
        $em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:meal-items:categorize'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Categorized 4 meal items.', $tester->getDisplay());

        $em->clear();
        $category = fn (MealItem $item) => $em->find(MealItem::class, $item->getId())->getCategory();
        self::assertSame(FoodCategory::Alcohol, $category($items['beer']));
        self::assertSame(FoodCategory::Alcohol, $category($items['beer again']));
        self::assertSame(FoodCategory::Snack, $category($items['cake']));
        self::assertSame(FoodCategory::Food, $category($items['soup']));
        self::assertSame(FoodCategory::Food, $category($items['corrected']), 'already categorized: untouched');

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('All meal items already have a category.', $tester->getDisplay());
    }
}
