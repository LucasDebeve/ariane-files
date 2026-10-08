<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A document is either a stored file or a link to an external video
 * (videos are never uploaded, only referenced by URL).
 */
enum DocumentKind: string
{
    case File = 'fichier';
    case VideoLink = 'video';
}
