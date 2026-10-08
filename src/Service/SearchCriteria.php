<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Folder;
use App\Entity\Tag;

final class SearchCriteria
{
    public function __construct(
        public readonly ?string $query = null,
        public readonly ?Folder $folder = null,
        public readonly ?Tag $tag = null,
        public readonly int $page = 1,
        public readonly int $perPage = 24,
    ) {
    }

    public function hasQuery(): bool
    {
        return null !== $this->query && '' !== trim($this->query);
    }
}
