<?php

namespace App\Tests\Functional;

use App\Entity\MealEntry;
use App\Entity\User;
use App\Nutrition\EstimatedItem;
use App\Nutrition\FoodCategory;
use App\Nutrition\MealEstimate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DataExportTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = (new User())->setEmail('me@example.com')->setPassword('x')->setTimezone('Europe/Riga');
        $this->em()->persist($this->user);
        $this->em()->flush();
        $this->client->loginUser($this->user);
    }

    public function testExportRequiresLogin(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/profile/export');

        self::assertResponseRedirects('/login');
    }

    public function testProfileLinksToTheExport(): void
    {
        $crawler = $this->client->request('GET', '/profile');

        self::assertSame('/profile/export', $crawler->selectLink('Export foods (JSON)')->attr('href'));
    }

    public function testFoodsAreGroupedByDayWithTheirNutrition(): void
    {
        $this->meal($this->user, '2 eggs and toast', '2026-10-20 06:00', [
            new EstimatedItem('Egg', 100, 143, 12.6, 0.7, 9.5),
            new EstimatedItem('Toast', 30, 79.5, 2.7, 14.7, 1),
        ]);
        $this->meal($this->user, 'a beer', '2026-10-20 18:00', [
            new EstimatedItem('Beer', 500, 215, 2.5, 18, 0, category: FoodCategory::Alcohol),
        ]);
        // 22:30 UTC is already the next day in Riga (UTC+3)
        $this->meal($this->user, 'chocolate', '2026-10-20 22:30', [
            new EstimatedItem('Dark chocolate', 20, 119.8, 1.6, 9.2, 8.5, category: FoodCategory::Snack),
        ]);

        $this->client->request('GET', '/profile/export');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString("\n    \"2026-10-20\": [", $this->client->getResponse()->getContent()); // readable: pretty-printed
        self::assertStringContainsString('attachment; filename=calorie-tracker-foods-', $this->client->getResponse()->headers->get('Content-Disposition'));

        $export = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(['2026-10-20', '2026-10-21'], array_keys($export));
        self::assertSame(['Egg', 'Toast', 'Beer'], array_column($export['2026-10-20'], 'food'));
        self::assertSame([
            'food' => 'Egg', 'meal' => 'Egg, Toast', 'time' => '09:00', 'category' => 'food',
            'grams' => 100, 'kcal' => 143, 'protein_g' => 12.6, 'carbs_g' => 0.7, 'fat_g' => 9.5,
        ], $export['2026-10-20'][0]);
        self::assertSame('alcohol', $export['2026-10-20'][2]['category']);
        self::assertSame('01:30', $export['2026-10-21'][0]['time']);
        self::assertSame('Dark chocolate', $export['2026-10-21'][0]['food']);
    }

    public function testOnlyTheUsersEstimatedMealsAreExported(): void
    {
        $other = (new User())->setEmail('other@example.com')->setPassword('x');
        $this->em()->persist($other);
        $this->meal($other, 'their lunch', '2026-10-20 10:00', [new EstimatedItem('Pizza', 300, 800, 30, 90, 30)]);
        $this->em()->persist(new MealEntry($this->user, 'not estimated yet', new \DateTimeImmutable('2026-10-20 12:00', new \DateTimeZone('UTC'))));
        $this->em()->flush();

        $this->client->request('GET', '/profile/export');

        self::assertSame('{}', $this->client->getResponse()->getContent());
    }

    /** @param list<EstimatedItem> $items */
    private function meal(User $user, string $text, string $utc, array $items): void
    {
        $meal = new MealEntry($user, $text, new \DateTimeImmutable($utc, new \DateTimeZone('UTC')));
        $meal->applyEstimate(new MealEstimate($items, 'test'), new \DateTimeImmutable());
        $this->em()->persist($meal);
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
