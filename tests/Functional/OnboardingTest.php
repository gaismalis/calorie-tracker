<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\WeightEntry;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OnboardingTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testNewUsersMustTellUsAboutThemselvesFirst(): void
    {
        $this->register();

        foreach (['/', '/weight', '/profile', '/day/2026-09-01', '/meals/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/welcome', message: $url);
        }

        $this->client->request('GET', '/welcome');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tell us about yourself');
        self::assertSelectorNotExists('.verify-banner', 'the email reminder waits until after this step');
    }

    public function testFormAsksForEverythingTheFormulaNeedsAndExplainsActivity(): void
    {
        $this->register();
        $crawler = $this->client->request('GET', '/welcome');

        self::assertSame(['male', 'female'], $crawler->filter('input[name="onboarding_form[sex]"]')->each(fn ($i) => $i->attr('value')));
        foreach (['birthDate', 'heightCm', 'weightKg'] as $field) {
            self::assertCount(1, $crawler->filter('input[name="onboarding_form['.$field.']"]'), $field);
        }
        self::assertCount(5, $crawler->filter('.activity-choice'));
        self::assertCount(5, $crawler->filter('input[name="onboarding_form[activityLevel]"]'), 'each choice rendered once');
        self::assertCount(1, $crawler->filter('input[name="onboarding_form[_token]"]'), 'CSRF token is in the form');
        self::assertSelectorTextContains('.activity-choices', "Don't count workouts here: log them as exercise and they're added on top, so nothing is counted twice.");
        self::assertSelectorTextContains('.activity-choices', 'walking or cycling to get around (e.g. commuting by bike)');
        self::assertSelectorTextContains('.activity-choices', 'Physical job');
    }

    public function testEverythingIsRequired(): void
    {
        $this->register();
        $this->client->request('GET', '/welcome');
        $this->client->submitForm('Continue');

        self::assertResponseStatusCodeSame(422);
        $text = $this->client->getCrawler()->filter('form')->last()->text();
        foreach (['Please choose one.', 'Please enter your birth date.', 'Please enter your height.', 'Please enter your weight.'] as $error) {
            self::assertStringContainsString($error, $text);
        }
        self::assertTrue($this->user()->isOnboardingRequired());
    }

    public function testCompletingItSavesTheProfileAndTodaysWeightAndShowsTheTarget(): void
    {
        $this->register();
        $this->client->request('GET', '/welcome');
        $this->client->submitForm('Continue', [
            'onboarding_form[sex]' => 'female',
            'onboarding_form[birthDate]' => (new \DateTimeImmutable('-40 years -10 days'))->format('Y-m-d'),
            'onboarding_form[heightCm]' => '165',
            'onboarding_form[weightKg]' => '65',
            'onboarding_form[activityLevel]' => 'light',
        ]);

        self::assertResponseRedirects('/');
        $user = $this->user();
        self::assertFalse($user->isOnboardingRequired());
        self::assertSame(Sex::Female, $user->getSex());
        self::assertSame(165.0, $user->getHeightCm());
        self::assertSame(ActivityLevel::Light, $user->getActivityLevel());
        $weight = $this->em()->getRepository(WeightEntry::class)->findOneBy(['user' => $user->getId()]);
        self::assertSame(65.0, $weight->getWeightKg());
        self::assertSame($user->today()->format('Y-m-d'), $weight->getDate()->format('Y-m-d'));

        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'All set!');
        // 10×65 + 6.25×165 − 5×40 − 161 = 1320.25 → 1320; × 1.375 = 1815
        self::assertSelectorTextContains('.stat-burned', '1 815');
        self::assertSelectorNotExists('.target-missing');

        $this->client->request('GET', '/welcome');
        self::assertResponseRedirects('/', message: 'done once, not shown again');
    }

    public function testInvalidValuesAreRejected(): void
    {
        $this->register();
        $this->client->request('GET', '/welcome');
        $this->client->submitForm('Continue', [
            'onboarding_form[sex]' => 'male',
            'onboarding_form[birthDate]' => (new \DateTimeImmutable('-5 years'))->format('Y-m-d'),
            'onboarding_form[heightCm]' => '20',
            'onboarding_form[weightKg]' => '5',
            'onboarding_form[activityLevel]' => 'moderate',
        ]);

        self::assertResponseStatusCodeSame(422);
        $text = $this->client->getCrawler()->filter('form')->last()->text();
        self::assertStringContainsString('You must be at least 13 years old', $text);
        self::assertStringContainsString('Height must be between 100 and 250 cm', $text);
        self::assertStringContainsString('Weight must be between 20 and 400 kg', $text);
        self::assertTrue($this->user()->isOnboardingRequired());
        self::assertNull($this->user()->getHeightCm());
    }

    public function testExistingUsersAreNotForcedThroughIt(): void
    {
        $user = (new User())->setEmail('old@example.com')->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/welcome');
        self::assertResponseRedirects('/');
    }

    public function testLogoutAndEmailConfirmationWorkBeforeOnboarding(): void
    {
        $this->register();

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/login');
    }

    private function register(): void
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', ['registration_form[email]' => 'new@example.com', 'registration_form[plainPassword]' => 'correct-horse']);
    }

    private function user(): User
    {
        $this->em()->clear();

        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'new@example.com']);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
