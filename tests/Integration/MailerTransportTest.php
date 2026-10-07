<?php

namespace App\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Bridge\Mailgun\Transport\MailgunApiTransport;

/** Production sends through Mailgun's API; MAILER_DSN must be understood by the app. */
class MailerTransportTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function dsns(): iterable
    {
        yield 'EU account' => ['mailgun+api://key-123:mg.example.com@default?region=eu', 'api.eu.mailgun.net'];
        yield 'US account' => ['mailgun+api://key-123:mg.example.com@default?region=us', 'api.mailgun.net'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dsns')]
    public function testMailgunApiDsnIsSupported(string $dsn, string $host): void
    {
        self::bootKernel();

        $transport = static::getContainer()->get('mailer.transport_factory')->fromString($dsn);

        self::assertInstanceOf(MailgunApiTransport::class, $transport);
        self::assertStringContainsString($host, (string) $transport);
        self::assertStringContainsString('mg.example.com', (string) $transport);
    }
}
