<?php

declare(strict_types=1);

namespace App\Storage;

enum StorageArea: string
{
    case Quarantine = 'quarantine';
    case Published = 'published';
}
