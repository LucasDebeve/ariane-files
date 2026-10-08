<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FolderRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_folder_slug', columns: ['slug'])]
#[UniqueEntity(fields: ['slug'], message: 'folder.slug.taken')]
class Folder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    #[ORM\Column(length: 140)]
    private string $slug = '';

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'RESTRICT')]
    private ?Folder $parent = null;

    /** @var Collection<int, Folder> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $children;

    /** @var Collection<int, Document> */
    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'folder')]
    private Collection $documents;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getParent(): ?Folder
    {
        return $this->parent;
    }

    public function setParent(?Folder $parent): static
    {
        if (null !== $parent && ($parent === $this || $parent->isDescendantOf($this))) {
            throw new \InvalidArgumentException('A folder cannot be moved inside itself.');
        }
        $this->parent = $parent;

        return $this;
    }

    public function isDescendantOf(Folder $folder): bool
    {
        for ($current = $this->parent; null !== $current; $current = $current->getParent()) {
            if ($current === $folder) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Folder> ancestors from the root down to the direct parent
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        for ($current = $this->parent; null !== $current; $current = $current->getParent()) {
            array_unshift($ancestors, $current);
        }

        return $ancestors;
    }

    public function getPath(): string
    {
        $names = array_map(static fn (Folder $f): string => $f->getName(), $this->getAncestors());
        $names[] = $this->name;

        return implode(' / ', $names);
    }

    public function getDepth(): int
    {
        return \count($this->getAncestors());
    }

    /**
     * @return Collection<int, Folder>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return $this->getPath();
    }
}
