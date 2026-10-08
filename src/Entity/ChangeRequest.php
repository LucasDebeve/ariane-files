<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ChangeRequestStatus;
use App\Enum\ChangeRequestType;
use App\Repository\ChangeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A proposal (addition, modification or deletion) waiting for a certified user's review.
 *
 * Payload keys (all optional depending on the type):
 *  - title, description, folderId, tags (list of names), videoUrl
 *  - file: {originalName, mimeType, size, sha256} (the object itself lives in quarantine under $quarantineKey)
 *  - reason: free text explaining a deletion or a modification
 */
#[ORM\Entity(repositoryClass: ChangeRequestRepository::class)]
#[ORM\Index(name: 'idx_change_request_status', columns: ['status'])]
class ChangeRequest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 20, enumType: ChangeRequestType::class)]
    private ChangeRequestType $type;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Document $document = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\Column(length: 100)]
    private string $proposerName;

    /** Set when the proposal was made by a logged-in certified user (used to forbid self-validation). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $proposerUser = null;

    #[ORM\Column(length: 20, enumType: ChangeRequestStatus::class)]
    private ChangeRequestStatus $status = ChangeRequestStatus::Pending;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $reviewer = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewComment = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $quarantineKey = null;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int|string $quarantineSize = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(ChangeRequestType $type, string $proposerName, array $payload = [], ?Document $document = null, ?User $proposerUser = null)
    {
        if (ChangeRequestType::Addition !== $type && null === $document) {
            throw new \InvalidArgumentException('Modification and deletion requests need a target document.');
        }
        $this->id = Uuid::v7();
        $this->type = $type;
        $this->proposerName = trim($proposerName);
        $this->payload = $payload;
        $this->document = $document;
        $this->proposerUser = $proposerUser;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getType(): ChangeRequestType
    {
        return $this->type;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    /**
     * Links an approved addition to the document it created (kept for history).
     */
    public function linkCreatedDocument(Document $document): void
    {
        if (ChangeRequestType::Addition !== $this->type || null !== $this->document) {
            throw new \LogicException('Only an addition can be linked to the document it created.');
        }
        $this->document = $document;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getTitle(): ?string
    {
        return isset($this->payload['title']) ? (string) $this->payload['title'] : null;
    }

    public function getDescription(): ?string
    {
        return isset($this->payload['description']) ? (string) $this->payload['description'] : null;
    }

    public function getFolderId(): ?int
    {
        return isset($this->payload['folderId']) ? (int) $this->payload['folderId'] : null;
    }

    /**
     * @return list<string>
     */
    public function getTagNames(): array
    {
        $tags = $this->payload['tags'] ?? [];

        return \is_array($tags) ? array_values(array_map('strval', $tags)) : [];
    }

    public function getVideoUrl(): ?string
    {
        return isset($this->payload['videoUrl']) ? (string) $this->payload['videoUrl'] : null;
    }

    /**
     * @return array{originalName: string, mimeType: string, size: int, sha256: string}|null
     */
    public function getFile(): ?array
    {
        $file = $this->payload['file'] ?? null;
        if (!\is_array($file)) {
            return null;
        }

        return [
            'originalName' => (string) ($file['originalName'] ?? ''),
            'mimeType' => (string) ($file['mimeType'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
            'sha256' => (string) ($file['sha256'] ?? ''),
        ];
    }

    public function getReason(): ?string
    {
        return isset($this->payload['reason']) ? (string) $this->payload['reason'] : null;
    }

    public function getProposerName(): string
    {
        return $this->proposerName;
    }

    public function getProposerUser(): ?User
    {
        return $this->proposerUser;
    }

    public function getStatus(): ChangeRequestStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return ChangeRequestStatus::Pending === $this->status;
    }

    public function approve(User $reviewer, ?string $comment): void
    {
        $this->review(ChangeRequestStatus::Approved, $reviewer, $comment);
    }

    public function reject(User $reviewer, string $comment): void
    {
        $this->review(ChangeRequestStatus::Rejected, $reviewer, $comment);
    }

    private function review(ChangeRequestStatus $status, User $reviewer, ?string $comment): void
    {
        if (!$this->isPending()) {
            throw new \LogicException('This change request has already been reviewed.');
        }
        $this->status = $status;
        $this->reviewer = $reviewer;
        $comment = null !== $comment ? trim($comment) : null;
        $this->reviewComment = '' === $comment ? null : $comment;
        $this->reviewedAt = new \DateTimeImmutable();
    }

    public function getReviewer(): ?User
    {
        return $this->reviewer;
    }

    public function getReviewComment(): ?string
    {
        return $this->reviewComment;
    }

    public function getQuarantineKey(): ?string
    {
        return $this->quarantineKey;
    }

    public function getQuarantineSize(): int
    {
        return (int) $this->quarantineSize;
    }

    public function setQuarantineFile(?string $key, int $size): void
    {
        $this->quarantineKey = $key;
        $this->quarantineSize = null === $key ? 0 : $size;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }
}
