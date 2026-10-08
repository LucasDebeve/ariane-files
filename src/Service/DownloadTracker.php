<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Repository\DownloadStatRepository;

/**
 * Daily aggregated counters only: no IP address, no visitor identifier.
 */
final class DownloadTracker
{
    public function __construct(private readonly DownloadStatRepository $stats)
    {
    }

    public function track(Document $document): void
    {
        $this->stats->increment($document, new \DateTimeImmutable('today'));
    }
}
