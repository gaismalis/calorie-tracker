<?php

namespace App\Tests\Integration;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserAdminCommandTest extends KernelTestCase
{
    public function testGrantAndRevokeAdmin(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('me@example.com')->setPassword('x');
        $em->persist($user);
        $em->flush();
        $command = new CommandTester((new Application(self::$kernel))->find('app:user:admin'));

        self::assertSame(0, $command->execute(['email' => ' Me@Example.com ']));
        self::assertStringContainsString('me@example.com is now an admin', $command->getDisplay());
        $em->refresh($user);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());

        self::assertSame(0, $command->execute(['email' => 'me@example.com', '--revoke' => true]));
        $em->refresh($user);
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testUnknownUserFails(): void
    {
        self::bootKernel();
        $command = new CommandTester((new Application(self::$kernel))->find('app:user:admin'));

        self::assertSame(1, $command->execute(['email' => 'nobody@example.com']));
        self::assertStringContainsString('No user with email', $command->getDisplay());
    }
}
