<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ChangeRequest;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\ChangeRequestType;
use App\Message\ProcessPublishedDocument;
use App\Repository\FolderRepository;
use App\Storage\DocumentStorage;
use App\Storage\StorageArea;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Applies or rejects a change request. Authorization is checked by
 * {@see \App\Security\Voter\ChangeRequestVoter}; the self-review rule is re-asserted here.
 */
final class ReviewService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderRepository $folders,
        private readonly TagResolver $tagResolver,
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $auditLogger,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param bool $allowOwn lifts the self-review rule; reserved to the console (app:proposals:approve-all --allow-own)
     */
    public function approve(ChangeRequest $request, User $reviewer, ?string $comment, bool $allowOwn = false): Document
    {
        if (!$allowOwn) {
            $this->assertCanReview($request, $reviewer);
        }
        $obsoleteKeys = [];
        $process = false;
        $quarantineKey = $request->getQuarantineKey();

        $document = $this->entityManager->wrapInTransaction(function () use ($request, $reviewer, $comment, &$obsoleteKeys, &$process): Document {
            $this->entityManager->lock($request, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($request);
            if (!$request->isPending()) {
                throw new \LogicException('This change request has already been reviewed.');
            }

            $document = match ($request->getType()) {
                ChangeRequestType::Addition => $this->applyAddition($request, $process),
                ChangeRequestType::Modification => $this->applyModification($request, $obsoleteKeys, $process),
                ChangeRequestType::Deletion => $this->applyDeletion($request),
            };

            $request->approve($reviewer, $comment);
            $this->auditLogger->log('change_request.approved', $request, [
                'type' => $request->getType()->value,
                'document' => $document->getId()->toRfc4122(),
                'proposer' => $request->getProposerName(),
                'reviewer' => $reviewer->getEmail(),
            ]);
            $this->entityManager->flush();

            return $document;
        });

        // No versioning: replaced objects are removed once the new version is committed.
        foreach ($obsoleteKeys as $key) {
            $this->deleteQuietly(StorageArea::Published, $key);
        }
        if ($process && null !== $quarantineKey) {
            $this->deleteQuietly(StorageArea::Quarantine, $quarantineKey);
        }
        if ($process) {
            $this->bus->dispatch(new ProcessPublishedDocument($document->getId()->toRfc4122()));
        }

        return $document;
    }

    public function reject(ChangeRequest $request, User $reviewer, string $comment): void
    {
        $this->assertCanReview($request, $reviewer);
        $this->entityManager->wrapInTransaction(function () use ($request, $reviewer, $comment): void {
            $this->entityManager->lock($request, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($request);
            $request->reject($reviewer, $comment);
            $this->auditLogger->log('change_request.rejected', $request, [
                'type' => $request->getType()->value,
                'proposer' => $request->getProposerName(),
            ]);
            $this->entityManager->flush();
        });
    }

    private function deleteQuietly(StorageArea $area, string $key): void
    {
        try {
            $this->storage->delete($area, $key);
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to delete object {key}: {message}', ['key' => $key, 'message' => $e->getMessage()]);
        }
    }

    private function assertCanReview(ChangeRequest $request, User $reviewer): void
    {
        if (null !== $request->getProposerUser() && $request->getProposerUser()->getId() === $reviewer->getId()) {
            throw new \LogicException('A certified user cannot review their own proposal.');
        }
    }

    private function applyAddition(ChangeRequest $request, bool &$process): Document
    {
        $document = new Document();
        $this->applyMetadata($document, $request);
        $this->applyContent($document, $request, $process);
        $this->entityManager->persist($document);
        $request->linkCreatedDocument($document);

        return $document;
    }

    /**
     * @param list<string> $obsoleteKeys
     */
    private function applyModification(ChangeRequest $request, array &$obsoleteKeys, bool &$process): Document
    {
        $document = $request->getDocument();
        if (null === $document || !$document->isPublished()) {
            throw new \LogicException('The target document is no longer published.');
        }
        $this->applyMetadata($document, $request);
        if (null !== $request->getQuarantineKey() || null !== $request->getVideoUrl()) {
            $obsoleteKeys = array_values(array_filter([$document->getStorageKey(), $document->getPreviewKey()]));
            $this->applyContent($document, $request, $process);
        }

        return $document;
    }

    private function applyDeletion(ChangeRequest $request): Document
    {
        $document = $request->getDocument();
        if (null === $document || !$document->isPublished()) {
            throw new \LogicException('The target document is no longer published.');
        }
        $document->moveToTrash();
        $this->auditLogger->log('document.trashed', $document, ['title' => $document->getTitle()]);

        return $document;
    }

    private function applyMetadata(Document $document, ChangeRequest $request): void
    {
        $document->setTitle((string) $request->getTitle());
        $document->setDescription($request->getDescription());
        $folderId = $request->getFolderId();
        $document->setFolder(null !== $folderId ? $this->folders->find($folderId) : null);
        $document->setTags($this->tagResolver->resolve($request->getTagNames()));
    }

    private function applyContent(Document $document, ChangeRequest $request, bool &$process): void
    {
        $file = $request->getFile();
        $quarantineKey = $request->getQuarantineKey();
        if (null !== $file && null !== $quarantineKey) {
            $publishedKey = $this->storage->copyToPublished($quarantineKey);
            $document->attachFile($publishedKey, $file['originalName'], $file['mimeType'], $file['size'], $file['sha256']);
            $request->setQuarantineFile(null, 0);
            $process = true;

            return;
        }
        $videoUrl = $request->getVideoUrl();
        if (null !== $videoUrl) {
            $document->attachVideoLink($videoUrl);

            return;
        }

        throw new \LogicException('The change request has neither a file nor a video link.');
    }
}
