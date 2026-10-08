<?php

declare(strict_types=1);

namespace App\Service\Antivirus;

final class VirusScannerFactory
{
    public static function create(string $driver, string $host, int $port, string $environment): VirusScanner
    {
        if ('clamav' === $driver) {
            return new ClamAvScanner($host, $port);
        }
        if ('prod' === $environment) {
            throw new \LogicException('The antivirus cannot be disabled in production (ANTIVIRUS_DRIVER must be "clamav").');
        }

        return new NoopVirusScanner();
    }
}
