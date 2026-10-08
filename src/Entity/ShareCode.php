<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ShareCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * The common access code, stored hashed only. The latest row is the active code;
 * rotating the code invalidates every visitor session granted with an older one.
 */
#[ORM\Entity(repositoryClass: ShareCodeRepository::class)]
class ShareCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column]
    private \DateTimeImmutable $rotatedAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $rotatedBy;

    public function __construct(string $codeHash, ?User $rotatedBy = null)
    {
        $this->codeHash = $codeHash;
        $this->rotatedBy = $rotatedBy;
        $this->rotatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getRotatedAt(): \DateTimeImmutable
    {
        return $this->rotatedAt;
    }

    public function getRotatedBy(): ?User
    {
        return $this->rotatedBy;
    }
}
