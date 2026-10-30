<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthTest extends WebTestCase
{
    public function testVisitorsSeeTheLandingPage(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Just say what you ate.');
        self::assertSame('/register', $crawler->selectLink('Start tracking')->attr('href'));
        self::assertSame('/login', $crawler->selectLink('I have an account')->attr('href'));
        self::assertSame('/register', $crawler->filter('.topbar .landing-nav')->selectLink('Sign up')->attr('href'));
        self::assertSame('/login', $crawler->filter('.topbar .landing-nav')->selectLink('Log in')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-controller=cat], .cat-call, .overview'), 'none of the app');
    }

    public function testOtherPagesStillNeedALogin(): void
    {
        $client = static::createClient();
        foreach (['/day/2026-10-20', '/weight', '/recipes'] as $page) {
            $client->request('GET', $page);
            self::assertResponseRedirects('/login', message: $page);
        }
    }

    public function testLoggedInUsersGetTheirDayInsteadOfTheLandingPage(): void
    {
        $client = static::createClient();
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.overview'));
        self::assertCount(0, $crawler->filter('.landing-nav, .hero'));
    }

    public function testRegistrationCreatesUserAndLogsIn(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');
        $client->submitForm('Register', [
            'registration_form[email]' => 'New.User@Example.com',
            'registration_form[plainPassword]' => 'correct-horse',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseRedirects('/welcome', message: 'next step: tell us about yourself');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Tell us about yourself');
        self::assertSelectorTextContains('.topbar', 'new.user@example.com');

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'new.user@example.com']);
        self::assertNotNull($user, 'email is stored lowercased');
        self::assertNotSame('correct-horse', $user->getPassword(), 'password is hashed');
    }

    public function testRegistrationRejectsDuplicateEmailAndShortPassword(): void
    {
        $client = static::createClient();
        $this->createUser('taken@example.com');

        $client->request('GET', '/register');
        $client->submitForm('Register', [
            'registration_form[email]' => 'taken@example.com',
            'registration_form[plainPassword]' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'There is already an account with this email');
        self::assertSelectorTextContains('form', 'This value is too short');
    }

    public function testLoginWithValidAndInvalidCredentials(): void
    {
        $client = static::createClient();
        $this->createUser('me@example.com', 'secret-password');

        $client->request('GET', '/login');
        $client->submitForm('Log in', ['_username' => 'me@example.com', '_password' => 'wrong-password']);
        $client->followRedirect();
        self::assertSelectorExists('.flash-error');

        $client->submitForm('Log in', ['_username' => 'me@example.com', '_password' => 'secret-password']);
        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('.day-title', 'Today');
    }

    private function createUser(string $email, string $password = 'password123'): User
    {
        $container = static::getContainer();
        $user = (new User())->setEmail($email);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, $password));
        $em = $container->get('doctrine.orm.entity_manager');
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
