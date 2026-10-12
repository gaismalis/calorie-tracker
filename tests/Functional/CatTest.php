<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Just for fun: a cat now and then sits on the screen until tapped away (behaviour in cat_controller.js). */
class CatTest extends WebTestCase
{
    public function testTheCatVisitsSignedInPages(): void
    {
        $client = static::createClient();
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        foreach (['/', '/weight', '/profile'] as $page) {
            $crawler = $client->request('GET', $page);
            $layer = $crawler->filter('.cat-layer[data-controller=cat]');
            self::assertCount(1, $layer, $page);
            self::assertSame('7', $layer->attr('data-cat-max-taps-value'), 'at most 7 taps to shoo it');
            self::assertLessThan((float) $layer->attr('data-cat-max-hours-value'), (float) $layer->attr('data-cat-min-hours-value'));
        }
    }

    public function testNoCatOnTheLoginPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertCount(0, $crawler->filter('[data-controller=cat]'));
    }

    public function testTheCatSchedulesItsVisitsAndRespectsReducedMotion(): void
    {
        $script = file_get_contents(__DIR__.'/../../assets/controllers/cat_controller.js');

        self::assertStringContainsString('localStorage', $script, 'the next visit survives page loads');
        self::assertStringContainsString('prefers-reduced-motion: reduce', $script);
        self::assertStringContainsString('Math.floor(Math.random() * this.maxTapsValue)', $script, '1 to maxTaps taps');
    }

    public function testTheSpriteSheetMatchesTheFramesTheControllerExpects(): void
    {
        $sheet = __DIR__.'/../../public/cat/cat-sprite.png';
        $script = file_get_contents(__DIR__.'/../../assets/controllers/cat_controller.js');
        preg_match('/CELL_WIDTH = (\d+)/', $script, $width);
        preg_match('/CELL_HEIGHT = (\d+)/', $script, $height);
        preg_match('/CELLS = (\d+)/', $script, $cells);

        self::assertStringContainsString("'/cat/cat-sprite.png'", $script);
        [$sheetWidth, $sheetHeight] = getimagesize($sheet);
        self::assertSame((int) $width[1] * (int) $cells[1], $sheetWidth, 'one row of equal cells');
        self::assertSame((int) $height[1], $sheetHeight);
        self::assertLessThan(500_000, filesize($sheet), 'small enough for mobile data');
        self::assertStringContainsString('CC0', file_get_contents(__DIR__.'/../../public/cat/README.txt'), 'licence noted');
    }
}
