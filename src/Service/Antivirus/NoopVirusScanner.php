<?php

declare(strict_types=1);

namespace App\Service\Antivirus;

/**
 * Only for development and tests; refused in production by {@see VirusScannerFactory}.
 */
final class NoopVirusScanner implements VirusScanner
{
    public function scan(string $path): void
    {
    }
}
