<?php

declare(strict_types=1);

namespace App\Service\Upload;

final class InspectedFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $originalName,
        public readonly string $extension,
        public readonly string $mimeType,
        public readonly int $size,
        public readonly string $sha256,
    ) {
    }
}
