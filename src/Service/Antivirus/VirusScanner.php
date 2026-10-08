<?php

declare(strict_types=1);

namespace App\Service\Antivirus;

interface VirusScanner
{
    /**
     * @throws InfectedFileException       when a threat is found
     * @throws ScannerUnavailableException when the file could not be scanned (fail closed)
     */
    public function scan(string $path): void;
}
