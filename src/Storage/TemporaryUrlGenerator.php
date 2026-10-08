<?php

declare(strict_types=1);

namespace App\Storage;

interface TemporaryUrlGenerator
{
    public function generate(StorageArea $area, string $key, string $downloadName, string $mimeType, \DateTimeImmutable $expiresAt): string;
}
