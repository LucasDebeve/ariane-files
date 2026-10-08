<?php

declare(strict_types=1);

namespace App\Service\Upload;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks an uploaded file against the white list using its real content signature
 * (libmagic + container inspection), never trusting the extension or the browser MIME type.
 */
final class FileInspector
{
    private const OLE_MIMES = ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/octet-stream'];
    private const ZIP_MIMES = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

    public function __construct(
        #[Autowire(param: 'app.max_upload_bytes')]
        private readonly int $maxBytes,
    ) {
    }

    public function inspect(string $path, string $originalName): InspectedFile
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RejectedFileException('upload.error.unreadable');
        }
        $size = (int) filesize($path);
        if (0 === $size) {
            throw new RejectedFileException('upload.error.empty');
        }
        if ($size > $this->maxBytes) {
            throw new RejectedFileException('upload.error.too_large', ['%max%' => (int) round($this->maxBytes / 1048576)]);
        }

        $originalName = self::sanitizeName($originalName);
        $extension = mb_strtolower(pathinfo($originalName, \PATHINFO_EXTENSION));
        if (!FileType::isAllowedExtension($extension)) {
            throw new RejectedFileException('upload.error.format');
        }

        $expected = FileType::ALLOWED[$extension];
        $detected = (string) (new \finfo(\FILEINFO_MIME_TYPE))->file($path);
        if (!$this->matches($path, $extension, $expected, $detected)) {
            throw new RejectedFileException('upload.error.signature');
        }

        return new InspectedFile($path, $originalName, $extension, $expected, $size, (string) hash_file('sha256', $path));
    }

    public static function sanitizeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        $name = trim($name, " .\t");
        if (mb_strlen($name) > 200) {
            $extension = pathinfo($name, \PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, \PATHINFO_FILENAME), 0, 190).('' !== $extension ? '.'.$extension : '');
        }

        return '' !== $name ? $name : 'document';
    }

    private function matches(string $path, string $extension, string $expected, string $detected): bool
    {
        if ($detected === $expected) {
            return true;
        }

        return match ($extension) {
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf' => false,
            'txt' => str_starts_with($detected, 'text/') && 'text/html' !== $detected && $this->isPlainText($path),
            'doc', 'ppt' => \in_array($detected, self::OLE_MIMES, true) && $this->isOleFile($path),
            'docx', 'pptx', 'xlsx' => \in_array($detected, self::ZIP_MIMES, true) && $this->ooxmlMainType($path) === $expected,
            'odt', 'odp', 'ods' => \in_array($detected, self::ZIP_MIMES, true) && $this->odfMimeType($path) === $expected,
            default => false,
        };
    }

    private function isPlainText(string $path): bool
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);

        return !str_contains($head, "\0") && 1 !== preg_match('/<\s*(script|html|svg)/i', $head);
    }

    private function isOleFile(string $path): bool
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" === file_get_contents($path, false, null, 0, 8);
    }

    private function ooxmlMainType(string $path): ?string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return null;
        }
        $types = $zip->getFromName('[Content_Types].xml');
        $zip->close();
        if (false === $types) {
            return null;
        }

        return match (true) {
            str_contains($types, 'wordprocessingml.document.main+xml') => FileType::ALLOWED['docx'],
            str_contains($types, 'presentationml.presentation.main+xml') => FileType::ALLOWED['pptx'],
            str_contains($types, 'spreadsheetml.sheet.main+xml') => FileType::ALLOWED['xlsx'],
            default => null,
        };
    }

    private function odfMimeType(string $path): ?string
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return null;
        }
        $mime = $zip->getFromName('mimetype');
        $zip->close();

        return false === $mime ? null : trim($mime);
    }
}
