<?php

declare(strict_types=1);

namespace App\Enum;

enum ChangeRequestStatus: string
{
    case Pending = 'en_attente';
    case Approved = 'validee';
    case Rejected = 'refusee';

    public function label(): string
    {
        return 'change_request.status.'.$this->value;
    }
}
