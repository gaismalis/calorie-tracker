<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:user:admin', description: 'Give a user access to the admin back office (/admin), or take it away with --revoke')]
final class UserAdminCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email of the user')] string $email,
        #[Option('Remove admin access instead of granting it')] bool $revoke = false,
    ): int {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]);
        if (!$user) {
            $io->error(sprintf('No user with email "%s".', $email));

            return Command::FAILURE;
        }

        $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER', 'ROLE_ADMIN']));
        if (!$revoke) {
            $roles[] = 'ROLE_ADMIN';
        }
        $user->setRoles($roles);
        $this->entityManager->flush();

        $io->success(sprintf('%s is %s an admin.', $user->getEmail(), $revoke ? 'no longer' : 'now'));

        return Command::SUCCESS;
    }
}
