<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\ShareAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:share-code:rotate', description: 'Generates a new share code (the previous one stops working immediately).')]
final class RotateShareCodeCommand
{
    public function __construct(private readonly ShareAccess $shareAccess)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Use this code instead of a random one (min. 8 characters)')] ?string $code = null): int
    {
        if (null !== $code && mb_strlen(ShareAccess::normalize($code)) < 8) {
            $io->error('The share code must contain at least 8 characters.');

            return Command::FAILURE;
        }
        $clear = $this->shareAccess->rotate(null, $code);
        $io->success(\sprintf('New share code: %s', $clear));
        $io->note('It is stored hashed and will not be shown again.');

        return Command::SUCCESS;
    }
}
