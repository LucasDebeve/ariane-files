<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Repository\ChangeRequestRepository;
use App\Repository\DocumentRepository;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Retention: trash purged after 30 days, rejected proposals' files after 7 days.
 */
final class Purger
{
    public const TRASH_RETENTION_DAYS = 30;
    public const REJECTED_RETENTION_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DocumentRepository $documents,
        private readonly ChangeRequestRepository $changeRequests,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{documents: int, quarantine: int}
     */
    public function purgeExpired(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $documents = 0;
        foreach ($this->documents->findTrashedBefore($now->modify(\sprintf('-%d days', self::TRASH_RETENTION_DAYS))) as $document) {
            $this->purgeDocument($document);
            ++$documents;
        }

        $quarantine = 0;
        foreach ($this->changeRequests->findRejectedWithFileBefore($now->modify(\sprintf('-%d days', self::REJECTED_RETENTION_DAYS))) as $request) {
            $this->deleteQuietly(StorageArea::Quarantine, (string) $request->getQuarantineKey());
            $request->setQuarantineFile(null, 0);
            ++$quarantine;
        }
        $this->entityManager->flush();

        return ['documents' => $documents, 'quarantine' => $quarantine];
    }

    /**
     * Permanently removes a trashed document and its stored objects.
     */
    public function purgeDocument(Document $document): void
    {
        if ($document->isPublished()) {
            throw new \LogicException('Only trashed documents can be purged.');
        }
        foreach (array_filter([$document->getStorageKey(), $document->getPreviewKey()]) as $key) {
            $this->deleteQuietly(StorageArea::Published, $key);
        }
        $this->auditLogger->log('document.purged', $document, ['title' => $document->getTitle()]);
        $this->entityManager->remove($document);
        $this->entityManager->flush();
    }

    private function deleteQuietly(StorageArea $area, string $key): void
    {
        try {
            if ($this->storage->exists($area, $key)) {
                $this->storage->delete($area, $key);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to purge object {key}: {message}', ['key' => $key, 'message' => $e->getMessage()]);
        }
    }
}
