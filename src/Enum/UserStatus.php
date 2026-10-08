<?php

declare(strict_types=1);

namespace App\Enum;

enum UserStatus: string
{
    case Pending = 'en_attente';
    case Active = 'actif';
    case Disabled = 'desactive';

    public function label(): string
    {
        return 'user.status.'.$this->value;
    }
}
