<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Profile\ActivityLevel;
use App\Profile\Sex;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProfileTest extends WebTestCase
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

    public function testProfileRequiresLogin(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
    }

    public function testSavingProfile(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Save', [
            'profile_form[sex]' => 'female',
            'profile_form[birthDate]' => '1990-05-20',
            'profile_form[heightCm]' => '168.5',
            'profile_form[activityLevel]' => 'moderate',
            'profile_form[weeklyGoalKg]' => '-0.5',
            'profile_form[timezone]' => 'Europe/London',
        ]);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Profile saved');

        $user = $this->reloadUser();
        self::assertSame(Sex::Female, $user->getSex());
        self::assertSame('1990-05-20', $user->getBirthDate()->format('Y-m-d'));
        self::assertSame(168.5, $user->getHeightCm());
        self::assertSame(ActivityLevel::Moderate, $user->getActivityLevel());
        self::assertSame('Europe/London', $user->getTimezone());
        self::assertSame(-0.5, $user->getWeeklyGoalKg());
    }

    public function testGoalDefaultsToKeepingWeightAndListsAllOptions(): void
    {
        $crawler = $this->client->request('GET', '/profile');

        $options = $crawler->filter('#profile_form_weeklyGoalKg option')->each(fn ($o) => $o->text());
        self::assertSame([
            'Lose 1 kg per week', 'Lose 0.75 kg per week', 'Lose 0.5 kg per week', 'Lose 0.25 kg per week',
            'Keep my weight', 'Gain 0.25 kg per week', 'Gain 0.5 kg per week',
        ], $options);
        self::assertSame('Keep my weight', $crawler->filter('#profile_form_weeklyGoalKg option[selected]')->text());
    }

    public function testUnlistedGoalIsRejected(): void
    {
        $crawler = $this->client->request('GET', '/profile');
        $form = $crawler->selectButton('Save')->form();
        $form['profile_form[weeklyGoalKg]']->disableValidation()->setValue('-3');
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(0.0, $this->reloadUser()->getWeeklyGoalKg());
    }

    public function testFieldsAreOptionalExceptTimezone(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Save', ['profile_form[heightCm]' => '180']);

        self::assertResponseRedirects('/');
        self::assertSame(180.0, $this->reloadUser()->getHeightCm());
        self::assertNull($this->reloadUser()->getSex());
    }

    public function testInvalidValuesAreRejectedAndNotSaved(): void
    {
        $this->client->request('GET', '/profile');
        $this->client->submitForm('Save', [
            'profile_form[heightCm]' => '20',
            'profile_form[birthDate]' => (new \DateTimeImmutable('-5 years'))->format('Y-m-d'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name=profile_form]', 'Height must be between 100 and 250 cm');
        self::assertSelectorTextContains('form[name=profile_form]', 'You must be at least 13 years old');
        self::assertNull($this->reloadUser()->getHeightCm());
    }

    private function reloadUser(): User
    {
        $this->em()->clear();

        return $this->em()->find(User::class, $this->user->getId());
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
