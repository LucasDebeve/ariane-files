<?php

declare(strict_types=1);

namespace App\Service\Antivirus;

final class InfectedFileException extends \RuntimeException
{
    public function __construct(public readonly string $signature)
    {
        parent::__construct(\sprintf('Infected file (%s).', $signature));
    }
}
