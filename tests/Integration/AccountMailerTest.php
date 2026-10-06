<?php

namespace App\Tests\Integration;

use App\Account\AccountMailer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/** Emails sent without a web request (e.g. from the worker or a command) must still link to the app's real address. */
class AccountMailerTest extends KernelTestCase
{
    public function testLinksUseTheConfiguredBaseUrlOutsideARequest(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $mailer = static::getContainer()->get(AccountMailer::class);

        $mailer->sendEmailConfirmation($user);
        $mailer->sendPasswordReset($user, static::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user));

        $base = $_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'];
        foreach (self::getMailerMessages() as $message) {
            $href = (new Crawler($message->getHtmlBody()))->filter('a')->attr('href');
            self::assertStringStartsWith($base.'/', $href);
        }
        self::assertCount(2, self::getMailerMessages());
    }
}
