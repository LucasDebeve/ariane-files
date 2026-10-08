<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DocumentKind;
use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(name: 'idx_document_status', columns: ['status'])]
// Created as GIN indexes by migrations; declared here so the schema diff stays clean.
#[ORM\Index(name: 'idx_document_search', columns: ['search_vector'])]
#[ORM\Index(name: 'idx_document_title_trgm', columns: ['title'])]
#[ORM\HasLifecycleCallbacks]
class Document
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 200)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(inversedBy: 'documents')]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Folder $folder = null;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class)]
    #[ORM\JoinTable(name: 'document_tag')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $tags;

    /** Denormalized tag names, indexed in the full-text search vector. */
    #[ORM\Column(type: Types::TEXT, options: ['default' => ''])]
    private string $tagsText = '';

    #[ORM\Column(length: 20, enumType: DocumentKind::class)]
    private DocumentKind $kind = DocumentKind::File;

    /** Object key in the "published" bucket (a random UUID, never the original name). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $storageKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $size = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $externalUrl = null;

    /** Object key of the generated PDF preview (office documents). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $previewKey = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extractedText = null;

    #[ORM\Column(type: 'tsvector', nullable: true, insertable: false, updatable: false)]
    private ?string $searchVector = null;

    #[ORM\Column(length: 20, enumType: DocumentStatus::class)]
    private DocumentStatus $status = DocumentStatus::Published;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $downloadCount = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = trim($title);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = null !== $description ? trim($description) : null;
        $this->description = '' === $description ? null : $description;

        return $this;
    }

    public function getFolder(): ?Folder
    {
        return $this->folder;
    }

    public function setFolder(?Folder $folder): static
    {
        $this->folder = $folder;

        return $this;
    }

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * @param iterable<Tag> $tags
     */
    public function setTags(iterable $tags): static
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            if (!$this->tags->contains($tag)) {
                $this->tags->add($tag);
            }
        }
        $this->tagsText = implode(' ', $this->tags->map(static fn (Tag $t): string => $t->getName())->toArray());

        return $this;
    }

    public function getTagsText(): string
    {
        return $this->tagsText;
    }

    public function getKind(): DocumentKind
    {
        return $this->kind;
    }

    public function isVideoLink(): bool
    {
        return DocumentKind::VideoLink === $this->kind;
    }

    public function attachFile(string $storageKey, string $originalFilename, string $mimeType, int $size, string $sha256): static
    {
        $this->kind = DocumentKind::File;
        $this->storageKey = $storageKey;
        $this->originalFilename = $originalFilename;
        $this->mimeType = $mimeType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->externalUrl = null;
        $this->previewKey = null;
        $this->extractedText = null;

        return $this;
    }

    public function attachVideoLink(string $url): static
    {
        $this->kind = DocumentKind::VideoLink;
        $this->externalUrl = $url;
        $this->storageKey = null;
        $this->originalFilename = null;
        $this->mimeType = null;
        $this->size = null;
        $this->sha256 = null;
        $this->previewKey = null;
        $this->extractedText = null;

        return $this;
    }

    public function getStorageKey(): ?string
    {
        return $this->storageKey;
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function getSize(): ?int
    {
        return null === $this->size ? null : (int) $this->size;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    public function getPreviewKey(): ?string
    {
        return $this->previewKey;
    }

    public function setPreviewKey(?string $previewKey): static
    {
        $this->previewKey = $previewKey;

        return $this;
    }

    public function getExtractedText(): ?string
    {
        return $this->extractedText;
    }

    public function setExtractedText(?string $extractedText): static
    {
        $this->extractedText = $extractedText;

        return $this;
    }

    public function getSearchVector(): ?string
    {
        return $this->searchVector;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return DocumentStatus::Published === $this->status;
    }

    public function moveToTrash(): void
    {
        $this->status = DocumentStatus::Trashed;
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->status = DocumentStatus::Published;
        $this->deletedAt = null;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getExtension(): ?string
    {
        if (null === $this->originalFilename) {
            return null;
        }
        $extension = pathinfo($this->originalFilename, \PATHINFO_EXTENSION);

        return '' === $extension ? null : mb_strtolower($extension);
    }
}
