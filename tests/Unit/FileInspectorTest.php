<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Upload\FileInspector;
use App\Service\Upload\RejectedFileException;
use App\Tests\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileInspectorTest extends TestCase
{
    private FileInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new FileInspector(1024 * 1024);
    }

    public function testAcceptsRealPdf(): void
    {
        $file = $this->inspector->inspect(Fixtures::file(Fixtures::pdf('Test')), 'Fiche.pdf');

        self::assertSame('application/pdf', $file->mimeType);
        self::assertSame('pdf', $file->extension);
        self::assertSame(64, \strlen($file->sha256));
    }

    public function testAcceptsPngImage(): void
    {
        $file = $this->inspector->inspect(Fixtures::file(Fixtures::png()), 'photo.PNG');

        self::assertSame('image/png', $file->mimeType);
    }

    public function testAcceptsDocxDetectedFromItsContentTypes(): void
    {
        $file = $this->inspector->inspect(Fixtures::file(Fixtures::docx()), 'compte-rendu.docx');

        self::assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $file->mimeType);
    }

    public function testAcceptsPlainText(): void
    {
        $file = $this->inspector->inspect(Fixtures::file("Bonjour\nles formateurs"), 'notes.txt');

        self::assertSame('text/plain', $file->mimeType);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function rejectedFiles(): iterable
    {
        yield 'executable renamed as pdf' => ["MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\0", 200), 'facture.pdf', 'upload.error.signature'];
        yield 'html disguised as text' => ['<html><script>alert(1)</script></html>', 'notes.txt', 'upload.error.signature'];
        yield 'zip renamed as docx' => [Fixtures::zip(), 'cv.docx', 'upload.error.signature'];
        yield 'svg is not allowed' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'logo.svg', 'upload.error.format'];
        yield 'php script' => ['<?php echo 1;', 'shell.php', 'upload.error.format'];
        yield 'no extension' => ['%PDF-1.4', 'document', 'upload.error.format'];
        yield 'empty file' => ['', 'vide.pdf', 'upload.error.empty'];
    }

    #[DataProvider('rejectedFiles')]
    public function testRejectsInvalidFiles(string $contents, string $name, string $expectedError): void
    {
        $this->expectException(RejectedFileException::class);
        $this->expectExceptionMessage($expectedError);

        $this->inspector->inspect(Fixtures::file($contents), $name);
    }

    public function testRejectsTooLargeFile(): void
    {
        $this->expectExceptionMessage('upload.error.too_large');

        (new FileInspector(100))->inspect(Fixtures::file(Fixtures::pdf(str_repeat('x', 200))), 'gros.pdf');
    }

    public function testSanitizesOriginalName(): void
    {
        self::assertSame('passwd.txt', FileInspector::sanitizeName('../../etc/passwd.txt'));
        self::assertSame('rapport.pdf', FileInspector::sanitizeName('C:\\Users\\moi\\rapport.pdf'));
        self::assertSame('document', FileInspector::sanitizeName('...'));
    }
}
