<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Purger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:purge', description: 'Purges the trash (30 days) and rejected proposals\' files (7 days).')]
final class PurgeCommand
{
    public function __construct(private readonly Purger $purger)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->purger->purgeExpired();
        $io->success(\sprintf('%d document(s) purged from the trash, %d quarantined file(s) deleted.', $result['documents'], $result['quarantine']));

        return Command::SUCCESS;
    }
}
