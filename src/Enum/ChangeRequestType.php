<?php

declare(strict_types=1);

namespace App\Enum;

enum ChangeRequestType: string
{
    case Addition = 'ajout';
    case Modification = 'modification';
    case Deletion = 'suppression';

    public function label(): string
    {
        return 'change_request.type.'.$this->value;
    }
}
