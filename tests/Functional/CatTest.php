<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Just for fun: when you open the app (at most every 30 minutes) a cat sits on the screen until tapped away (behaviour in cat_controller.js). */
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
            self::assertSame('30', $layer->attr('data-cat-gap-minutes-value'), 'at most once every 30 minutes');
        }
    }

    public function testThePawButtonOnTheMainPageCallsTheCatWithACooldown(): void
    {
        $client = static::createClient();
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/');
        $paw = $crawler->filter('button.cat-call');
        self::assertCount(1, $paw);
        self::assertSame('🐾', $paw->text());
        self::assertSame('cat-call', $paw->attr('data-controller'));
        $layer = $crawler->filter('.cat-layer');
        self::assertSame('5', $layer->attr('data-cat-call-cooldown-minutes-value'), 'at most every 5 minutes');
        self::assertStringContainsString('cat:call@window->cat#call', $layer->attr('data-action'));

        $script = file_get_contents(__DIR__.'/../../assets/controllers/cat_controller.js');
        self::assertStringContainsString("'Lasagna?'", $script, 'answers when it comes');
        self::assertStringContainsString("\"I'm cleaning myself\"", $script, 'excuses when called too soon');
        self::assertStringContainsString("this.dispatch('call', { prefix: 'cat'", file_get_contents(__DIR__.'/../../assets/controllers/cat_call_controller.js'));
    }

    public function testTheSwitchAboveThePawTurnsTheCatOffAndThePawTurnsItBackOn(): void
    {
        $client = static::createClient();
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/?date=2026-10-20');
        $switch = $crawler->filter('.cat-controls form.cat-toggle button[role=switch]');
        self::assertSame('true', $switch->attr('aria-checked'));
        self::assertSame('Turn the cat off', $switch->attr('title'));
        self::assertSame('cat-call#call', $crawler->filter('.cat-controls .cat-call')->attr('data-action'), 'the switch is above the paw');

        $client->submit($switch->form());
        self::assertResponseRedirects('/?date=2026-10-20', 303, 'back to the same day');
        self::assertFalse($this->catEnabled($user));

        $crawler = $client->request('GET', '/');
        self::assertCount(0, $crawler->filter('[data-controller=cat]'), 'no visits');
        self::assertCount(0, $crawler->filter('.cat-toggle'), 'no switch');
        $paw = $crawler->filter('.cat-controls .cat-call');
        self::assertCount(1, $paw, 'the paw stays');
        self::assertSame('submit', $paw->attr('type'), 'and turns the cat back on');
        self::assertSame('cat-call#wake', $paw->attr('data-action'), 'and calls it');
        self::assertCount(0, $client->request('GET', '/weight')->filter('[data-controller=cat]'), 'off on every page');

        $client->submit($client->request('GET', '/')->filter('.cat-call')->form());
        self::assertTrue($this->catEnabled($user));
        self::assertCount(1, $client->request('GET', '/')->filter('[data-controller=cat]'));
        self::assertCount(1, $client->getCrawler()->filter('.cat-toggle'), 'the switch is back');

        $calling = file_get_contents(__DIR__.'/../../assets/controllers/cat_call_controller.js');
        self::assertStringContainsString('sessionStorage.setItem(WAKE_KEY', $calling);
        $cat = file_get_contents(__DIR__.'/../../assets/controllers/cat_controller.js');
        self::assertStringContainsString('sessionStorage.getItem(WAKE_KEY)', $cat, 'the cat comes when the page is back');
    }

    public function testTheSwitchNeedsAValidTokenAndOnlyGoesBackWithinTheSite(): void
    {
        $client = static::createClient();
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->request('POST', '/settings/cat', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->catEnabled($user));

        $form = $client->request('GET', '/')->filter('.cat-toggle button')->form();
        $form['back']->setValue('//evil.example.com/');
        $client->submit($form);
        self::assertResponseRedirects('/', 303);
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
        self::assertStringContainsString('const ARRIVAL_SECONDS = [1, 5];', $script, 'within 5 seconds of opening the app');
        self::assertStringContainsString('this.gapMinutesValue * 60 * 1000', $script, 'next visit after the gap');
        self::assertStringContainsString('bored()', $script, 'ignored, it pulls faces and walks around');
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

    private function catEnabled(User $user): bool
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(User::class, $user->getId())->isCatEnabled();
    }
}
