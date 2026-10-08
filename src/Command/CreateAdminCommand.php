<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:admin:create', description: 'Creates (or promotes) the administrator account.')]
final class CreateAdminCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address')] string $email,
        #[Argument(description: 'Display name')] string $name = 'Administrateur',
        #[Option(description: 'Read the password from standard input (for provisioning scripts)')] bool $passwordStdin = false,
    ): int {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($email))]) ?? (new User())->setEmail($email)->setDisplayName($name);

        $validate = static function (?string $value): string {
            if (null === $value || mb_strlen($value) < 12) {
                throw new \RuntimeException('The password must contain at least 12 characters.');
            }

            return $value;
        };
        $password = $passwordStdin
            ? $validate(rtrim((string) stream_get_contents(\STDIN), "\r\n"))
            : $io->askHidden('Password (min. 12 characters)', $validate);
        $user->setPassword($this->hasher->hashPassword($user, (string) $password));
        $user->setRoles([User::ROLE_CERTIFIED, User::ROLE_ADMIN]);
        $user->approve();

        $violations = $this->validator->validate($user);
        if (\count($violations) > 0) {
            foreach ($violations as $violation) {
                $io->error($violation->getPropertyPath().': '.$violation->getMessage());
            }

            return Command::FAILURE;
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $io->success(\sprintf('Administrator %s is ready. Two-factor authentication will be configured at first login.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
