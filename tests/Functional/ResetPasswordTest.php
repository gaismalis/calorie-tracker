<?php

namespace App\Tests\Functional;

use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ResetPasswordTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $user = (new User())->setEmail('me@example.com');
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'old-password'));
        $this->em()->persist($user);
        $this->em()->flush();
    }

    public function testLoginPageLinksToForgotPassword(): void
    {
        $this->client->request('GET', '/login');
        $this->client->clickLink('Forgot your password?');

        self::assertSelectorTextContains('h1', 'Forgot your password?');
    }

    public function testFullResetFlow(): void
    {
        $link = $this->requestLink('Me@Example.com');

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/reset-password/reset', message: 'token moves out of the URL');
        $this->client->followRedirect();
        $this->client->submitForm('Save new password', [
            'form[plainPassword][first]' => 'brand-new-password',
            'form[plainPassword][second]' => 'brand-new-password',
        ]);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'Your password has been changed');

        $this->client->submitForm('Log in', ['_username' => 'me@example.com', '_password' => 'brand-new-password']);
        self::assertResponseRedirects('/');

        $user = $this->user();
        self::assertTrue($user->isVerified(), 'resetting via email proves the address works');
        self::assertSame(0, $this->em()->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testLinkWorksOnlyOnce(): void
    {
        $link = $this->requestLink('me@example.com');
        $this->client->request('GET', $link);
        $this->client->followRedirect();
        $this->client->submitForm('Save new password', ['form[plainPassword][first]' => 'brand-new-password', 'form[plainPassword][second]' => 'brand-new-password']);

        $this->client->request('GET', $link);
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
    }

    public function testUnknownEmailLooksTheSameButSendsNothing(): void
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Send reset link', ['form[email]' => 'nobody@example.com']);

        self::assertQueuedEmailCount(0);
        self::assertResponseRedirects('/reset-password/check-email');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'If an account exists for that address');
    }

    public function testSecondRequestWithin15MinutesSendsNothingAndLooksTheSame(): void
    {
        $this->requestLink('me@example.com');

        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Send reset link', ['form[email]' => 'me@example.com']);

        self::assertQueuedEmailCount(0);
        self::assertResponseRedirects('/reset-password/check-email');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function badPasswords(): iterable
    {
        yield 'too short' => ['short', 'short', 'This value is too short'];
        yield 'not the same' => ['brand-new-password', 'brand-new-passwort', 'The two passwords are not the same'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPasswords')]
    public function testInvalidNewPasswordIsRejected(string $first, string $second, string $error): void
    {
        $this->client->request('GET', $this->requestLink('me@example.com'));
        $this->client->followRedirect();
        $this->client->submitForm('Save new password', ['form[plainPassword][first]' => $first, 'form[plainPassword][second]' => $second]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', $error);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($this->user(), 'old-password'));
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->client->request('GET', '/reset-password/reset/not-a-real-token');
        $this->client->followRedirect();

        self::assertResponseRedirects('/reset-password');
        $this->client->followRedirect();
        self::assertSelectorExists('.flash-error');
    }

    public function testResetPageWithoutTokenSendsYouToTheRequestForm(): void
    {
        $this->client->request('GET', '/reset-password/reset');

        self::assertResponseRedirects('/reset-password');
    }

    /** Requests a reset link and returns its path from the email. */
    private function requestLink(string $email): string
    {
        $this->client->request('GET', '/reset-password');
        $this->client->submitForm('Send reset link', ['form[email]' => $email]);

        self::assertQueuedEmailCount(1);
        $message = self::getMailerMessage();
        self::assertEmailAddressContains($message, 'To', 'me@example.com');
        self::assertEmailHeaderSame($message, 'Subject', 'Reset your password');

        $href = (new Crawler($message->getHtmlBody()))->filter('a')->attr('href');
        self::assertStringStartsWith('http://localhost/reset-password/reset/', $href, 'absolute link to this app (test host)');

        return parse_url($href, PHP_URL_PATH);
    }

    private function user(): User
    {
        $this->em()->clear();

        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'me@example.com']);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
