<?php

declare(strict_types=1);

namespace App\Service\Preview;

interface PdfConverter
{
    /**
     * Converts an office document to PDF for the in-browser preview.
     *
     * @param resource $stream
     *
     * @return string|null PDF bytes, or null when conversion is unavailable
     */
    public function convert($stream, string $filename): ?string;
}
