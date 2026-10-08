<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DownloadStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Daily aggregated download counter. No IP address or visitor identifier is stored.
 */
#[ORM\Entity(repositoryClass: DownloadStatRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_download_stat_day', columns: ['document_id', 'day'])]
class DownloadStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $day;

    #[ORM\Column]
    private int $count = 0;

    public function __construct(Document $document, \DateTimeImmutable $day, int $count = 0)
    {
        $this->document = $document;
        $this->day = $day;
        $this->count = $count;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getDay(): \DateTimeImmutable
    {
        return $this->day;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
