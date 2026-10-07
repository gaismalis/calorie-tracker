<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\WeightEntry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class QuickWeightTest extends WebTestCase
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

    public function testFirstWeighInStartsAt75WithAWideRange(): void
    {
        $crawler = $this->client->request('GET', '/weight/quick');

        self::assertSelectorTextContains('#entry-title', 'Your weight today');
        self::assertSelectorTextContains('.quick-weight', 'Your first weigh-in');
        self::assertSame('75.0', $crawler->filter('input[name=weight_kg]')->attr('value'));
        $slider = $crawler->filter('input[type=range]');
        self::assertSame(['40', '150', '0.1'], [$slider->attr('min'), $slider->attr('max'), $slider->attr('step')]);
    }

    public function testStartsAtTheLastWeightWithTenKilosEitherWay(): void
    {
        $this->store('-5 days', 82.4);
        $this->store('-30 days', 85.0);

        $crawler = $this->client->request('GET', '/weight/quick');

        self::assertSame('82.4', $crawler->filter('input[name=weight_kg]')->attr('value'));
        $slider = $crawler->filter('input[type=range]');
        self::assertSame(['72', '93', '82.4'], [$slider->attr('min'), $slider->attr('max'), $slider->attr('value')]);
        self::assertSelectorNotExists('.quick-weight p.small');
    }

    public function testSavingLogsTodayAndReturnsToTheMainPage(): void
    {
        $this->store('-1 day', 82.0);

        $this->client->request('GET', '/weight/quick');
        $this->client->submitForm('Save', ['weight_kg' => '81,6']);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Saved 81.6 kg.');
        self::assertSame(81.6, $this->weightOn($this->user->today())?->getWeightKg());
    }

    public function testLoggingTheSameDayAgainReplacesItAndSaysSo(): void
    {
        $this->store('today', 80.0);

        $crawler = $this->client->request('GET', '/weight/quick');
        self::assertSelectorTextContains('.quick-weight', 'You logged 80.0 kg today. Saving replaces it.');
        self::assertSame('80.0', $crawler->filter('input[name=weight_kg]')->attr('value'));

        $this->client->submitForm('Save', ['weight_kg' => '79.8']);

        self::assertCount(1, $this->em()->getRepository(WeightEntry::class)->findAll());
        self::assertSame(79.8, $this->weightOn($this->user->today())?->getWeightKg());
    }

    public function testOnAPastDayItSavesForThatDay(): void
    {
        $day = $this->user->today()->modify('-3 days');

        $this->client->request('GET', '/weight/quick?date='.$day->format('Y-m-d'));
        self::assertSelectorTextContains('#entry-title', 'Your weight on '.$day->format('l, j M'));
        $this->client->submitForm('Save', ['weight_kg' => '83.1']);

        self::assertResponseRedirects('/day/'.$day->format('Y-m-d'));
        self::assertSame(83.1, $this->weightOn($day)?->getWeightKg());
        self::assertNull($this->weightOn($this->user->today()));
    }

    public function testFutureDatesFallBackToToday(): void
    {
        $this->client->request('GET', '/weight/quick?date='.$this->user->today()->modify('+2 days')->format('Y-m-d'));

        self::assertSelectorTextContains('#entry-title', 'Your weight today');
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'too low' => ['10'];
        yield 'too high' => ['401'];
        yield 'not a number' => ['heavy'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidWeightIsRejected(string $value): void
    {
        $this->client->request('GET', '/weight/quick');
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $form->disableValidation()->setValues(['weight_kg' => $value]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.quick-weight', 'Weight must be between 20 and 400 kg');
        self::assertSame([], $this->em()->getRepository(WeightEntry::class)->findAll());
    }

    public function testCsrfIsRequired(): void
    {
        $this->client->request('POST', '/weight/quick', ['weight_kg' => '80', '_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testInsideTheDialogItLeavesTheFrameAsAWholePage(): void
    {
        $this->client->request('GET', '/weight/quick', server: ['HTTP_TURBO_FRAME' => 'entry-panel']);
        $form = $this->client->getCrawler()->selectButton('Save')->form();
        $this->client->submit($form, ['weight_kg' => '80'], ['HTTP_TURBO_FRAME' => 'entry-panel']);

        self::assertResponseRedirects('/frame-exit?to=/');
    }

    private function store(string $when, float $kg): void
    {
        $this->em()->persist(new WeightEntry($this->em()->find(User::class, $this->user->getId()), $this->user->today()->modify($when), $kg));
        $this->em()->flush();
    }

    private function weightOn(\DateTimeImmutable $day): ?WeightEntry
    {
        $this->em()->clear();

        return $this->em()->getRepository(WeightEntry::class)->findOneBy(['date' => new \DateTimeImmutable($day->format('Y-m-d'), new \DateTimeZone('UTC'))]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
