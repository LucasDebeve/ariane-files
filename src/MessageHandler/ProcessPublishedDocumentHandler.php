<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\ProcessPublishedDocument;
use App\Repository\DocumentRepository;
use App\Service\Preview\PdfConverter;
use App\Service\TextExtraction\TextExtractor;
use App\Service\Upload\FileType;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final class ProcessPublishedDocumentHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStorage $storage,
        private readonly TextExtractor $textExtractor,
        private readonly PdfConverter $pdfConverter,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ProcessPublishedDocument $message): void
    {
        if (!Uuid::isValid($message->documentId)) {
            return;
        }
        $document = $this->documents->find(Uuid::fromString($message->documentId));
        $key = $document?->getStorageKey();
        if (null === $document || null === $key || !$this->storage->exists(StorageArea::Published, $key)) {
            return;
        }

        if (null === $document->getExtractedText()) {
            $stream = $this->storage->readStream(StorageArea::Published, $key);
            try {
                $text = $this->textExtractor->extract($stream, (string) $document->getMimeType());
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (null !== $text) {
                $document->setExtractedText($text);
            }
        }

        if (null === $document->getPreviewKey() && FileType::isOffice($document->getMimeType())) {
            $stream = $this->storage->readStream(StorageArea::Published, $key);
            try {
                $pdf = $this->pdfConverter->convert($stream, (string) $document->getOriginalFilename());
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (null !== $pdf) {
                $previewKey = 'previews/'.Uuid::v4()->toRfc4122().'.pdf';
                $this->storage->write(StorageArea::Published, $previewKey, $pdf);
                $document->setPreviewKey($previewKey);
            }
        }

        $this->entityManager->flush();
    }
}
