<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(name: 'idx_audit_log_created_at', columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $actor;

    /** Human readable actor, kept even if the user account is deleted. */
    #[ORM\Column(length: 200)]
    private string $actorLabel;

    #[ORM\Column(length: 60)]
    private string $action;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $targetType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $targetId;

    /** @var array<string, scalar|null> */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(?User $actor, string $actorLabel, string $action, ?string $targetType = null, ?string $targetId = null, array $details = [])
    {
        $this->actor = $actor;
        $this->actorLabel = mb_substr($actorLabel, 0, 200);
        $this->action = $action;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->details = $details;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getActorLabel(): string
    {
        return $this->actorLabel;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?string
    {
        return $this->targetId;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
