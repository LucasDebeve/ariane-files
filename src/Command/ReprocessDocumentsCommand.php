<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\ProcessPublishedDocument;
use App\Repository\DocumentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:documents:reprocess', description: 'Queues text extraction / preview for documents that do not have them yet.')]
final class ReprocessDocumentsCommand
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Option] int $limit = 500): int
    {
        $count = 0;
        foreach ($this->documents->findWithoutExtractedText($limit) as $document) {
            $this->bus->dispatch(new ProcessPublishedDocument($document->getId()->toRfc4122()));
            ++$count;
        }
        $io->success(\sprintf('%d document(s) queued.', $count));

        return Command::SUCCESS;
    }
}
