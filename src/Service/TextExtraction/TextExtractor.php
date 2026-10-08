<?php

declare(strict_types=1);

namespace App\Service\TextExtraction;

interface TextExtractor
{
    /**
     * @param resource $stream
     *
     * @return string|null the extracted text, or null when extraction is unavailable (retry later)
     */
    public function extract($stream, string $mimeType): ?string;
}
