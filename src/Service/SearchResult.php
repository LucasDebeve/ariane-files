<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;

final class SearchResult
{
    /**
     * @param list<Document> $documents
     */
    public function __construct(
        public readonly array $documents,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function pageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }
}
