<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Antivirus\ClamAvScanner;
use App\Service\Antivirus\InfectedFileException;
use App\Service\Antivirus\ScannerUnavailableException;
use App\Service\Antivirus\VirusScannerFactory;
use PHPUnit\Framework\TestCase;

final class ClamAvScannerTest extends TestCase
{
    public function testCleanResponse(): void
    {
        ClamAvScanner::interpret('stream: OK');
        $this->addToAssertionCount(1);
    }

    public function testInfectedResponse(): void
    {
        try {
            ClamAvScanner::interpret('stream: Win.Test.EICAR_HDB-1 FOUND');
            self::fail('An infected file must be rejected.');
        } catch (InfectedFileException $e) {
            self::assertSame('Win.Test.EICAR_HDB-1', $e->signature);
        }
    }

    public function testUnexpectedResponseFailsClosed(): void
    {
        $this->expectException(ScannerUnavailableException::class);
        ClamAvScanner::interpret('INSTREAM size limit exceeded. ERROR');
    }

    public function testUnreachableDaemonFailsClosed(): void
    {
        $this->expectException(ScannerUnavailableException::class);
        (new ClamAvScanner('127.0.0.1', 1))->scan(__FILE__);
    }

    public function testAntivirusCannotBeDisabledInProduction(): void
    {
        $this->expectException(\LogicException::class);
        VirusScannerFactory::create('none', 'clamav', 3310, 'prod');
    }
}
