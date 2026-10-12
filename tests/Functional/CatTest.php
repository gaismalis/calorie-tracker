<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
            self::assertSame('5', $layer->attr('data-cat-max-taps-value'), 'at most 5 taps to shoo it');
            self::assertNull($layer->attr('data-cat-soon-value'), 'no hurry without a fresh login');
            self::assertLessThan((float) $layer->attr('data-cat-max-hours-value'), (float) $layer->attr('data-cat-min-hours-value'));
        }
    }

    public function testRightAfterLoggingInTheCatComesSoonOnce(): void
    {
        $client = static::createClient();
        $user = (new User())->setEmail('me@example.com');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'correct-horse'));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['_username' => 'me@example.com', '_password' => 'correct-horse']);
        $crawler = $client->followRedirect();
        while ($client->getResponse()->isRedirection()) {
            $crawler = $client->followRedirect();
        }

        self::assertSame('true', $crawler->filter('.cat-layer')->attr('data-cat-soon-value'), 'first page after login');
        self::assertNull($client->request('GET', $client->getRequest()->getPathInfo())->filter('.cat-layer')->attr('data-cat-soon-value'), 'only once');
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
        self::assertStringContainsString('const SOON_SECONDS = [1, 5];', $script, 'within 5 seconds after login');
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
