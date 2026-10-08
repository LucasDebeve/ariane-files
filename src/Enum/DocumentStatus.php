<?php

declare(strict_types=1);

namespace App\Enum;

enum DocumentStatus: string
{
    case Published = 'publie';
    case Trashed = 'corbeille';
}
