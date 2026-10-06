<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mime\Email;

class EmailVerificationTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testRegistrationSendsAConfirmationLinkThatVerifiesTheAddress(): void
    {
        $link = $this->register('new@example.com');

        self::assertFalse($this->user('new@example.com')->isVerified());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'We sent a link to new@example.com');
        self::assertSelectorTextContains('.verify-banner', 'Please confirm your email address');

        $this->client->request('GET', $link);
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-success', 'your email address is confirmed');
        self::assertSelectorNotExists('.verify-banner');
        self::assertTrue($this->user('new@example.com')->isVerified());
    }

    public function testLinkWorksWithoutBeingLoggedIn(): void
    {
        $link = $this->register('new@example.com');
        $this->client->request('GET', '/logout');

        $this->client->request('GET', $link);

        self::assertResponseRedirects('/login');
        self::assertTrue($this->user('new@example.com')->isVerified());
    }

    public function testTamperedLinkIsRejected(): void
    {
        $link = $this->register('new@example.com');

        $this->client->request('GET', str_replace('signature=', 'signature=x', $link));
        $this->client->followRedirect();

        self::assertSelectorExists('.flash-error');
        self::assertFalse($this->user('new@example.com')->isVerified());
    }

    public function testLinkForAnotherUserIsRejected(): void
    {
        $link = $this->register('new@example.com');
        $other = (new User())->setEmail('other@example.com')->setPassword('x');
        $this->em()->persist($other);
        $this->em()->flush();

        $this->client->request('GET', preg_replace('/id=\d+/', 'id='.$other->getId(), $link));
        $this->client->followRedirect();

        self::assertSelectorExists('.flash-error');
        self::assertFalse($this->user('other@example.com')->isVerified());
    }

    public function testResendIsThrottledToOncePerMinute(): void
    {
        $this->register('new@example.com');
        $this->client->followRedirect();

        $this->client->submit($this->client->getCrawler()->filter('.verify-banner form')->form());
        self::assertQueuedEmailCount(0, message: 'just sent at registration');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'wait a minute');

        $user = $this->user('new@example.com');
        $user->setVerificationSentAt(new \DateTimeImmutable('-2 minutes'));
        $this->em()->flush();

        $this->client->submit($this->client->getCrawler()->filter('.verify-banner form')->form());
        self::assertQueuedEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'new@example.com');
    }

    public function testResendRequiresCsrfToken(): void
    {
        $this->register('new@example.com');

        $this->client->request('POST', '/verify/email/resend', ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
    }

    /** Registers and returns the confirmation link from the email. */
    private function register(string $email): string
    {
        $this->client->request('GET', '/register');
        $this->client->submitForm('Register', ['registration_form[email]' => $email, 'registration_form[plainPassword]' => 'correct-horse']);

        self::assertQueuedEmailCount(1);
        /** @var Email $message */
        $message = self::getMailerMessage();
        self::assertEmailAddressContains($message, 'To', $email);
        self::assertEmailHeaderSame($message, 'Subject', 'Confirm your email address');

        $link = (new Crawler($message->getHtmlBody()))->filter('a')->attr('href');
        self::assertStringContainsString('/verify/email?', $link);

        return parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
    }

    private function user(string $email): User
    {
        $this->em()->clear();

        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
