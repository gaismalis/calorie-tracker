<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** The "Soft" layout: pill menu at the top, a tab bar with the ＋ in the middle on phones (CSS decides which shows). */
class LayoutTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->user = (new User())->setEmail('kris@example.com')->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($this->user);
        $em->flush();
        $this->client->loginUser($this->user);
    }

    public function testTheMenusMarkTheCurrentPage(): void
    {
        foreach (['/' => 'Today', '/weight' => 'Weight', '/recipes' => 'Recipes', '/profile' => 'Profile'] as $page => $name) {
            $crawler = $this->client->request('GET', $page);
            self::assertSame($name, $crawler->filter('.topbar .main-nav a[aria-current=page]')->text(), $page);
            self::assertStringContainsString($name, $crawler->filter('.tabbar a[aria-current=page]')->text(), $page);
        }
    }

    public function testTheTabBarsPlusIsTheAddMenuOnTheMainPageAndLogsAMealElsewhere(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertCount(1, $crawler->filter('.tabbar .tabbar-gap'), 'the add menu (.add-entry) sits in the gap');
        self::assertCount(1, $crawler->filter('.add-entry .fab'));

        $crawler = $this->client->request('GET', '/weight');
        self::assertSame('/meals/new', $crawler->filter('.tabbar a.tabbar-add')->attr('href'));
    }

    public function testTheAvatarShowsTheFirstLetterAndOpensTheProfile(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame('k', $crawler->filter('.topbar .avatar')->text());
        self::assertSame('/profile', $crawler->filter('.topbar .avatar')->attr('href'));
    }

    public function testNoMenuWhileTellingUsAboutYourself(): void
    {
        $this->user->setOnboardingRequired(true);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $this->client->request('GET', '/welcome');

        self::assertCount(0, $crawler->filter('.main-nav, .tabbar'));
    }
}
