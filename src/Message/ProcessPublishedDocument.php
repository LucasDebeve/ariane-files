<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Asynchronous post-publication work: text extraction (Tika/OCR) and PDF preview (Gotenberg).
 */
final class ProcessPublishedDocument
{
    public function __construct(public readonly string $documentId)
    {
    }
}
