<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\LoadDemoCommand;

/**
 * Generates small but genuine sample files for tests.
 */
final class Fixtures
{
    public static function file(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ariane_test_');
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * A file carrying a given name (the browser-side file name matters for uploads).
     */
    public static function named(string $contents, string $name): string
    {
        $dir = sys_get_temp_dir().'/ariane_test_'.bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir.'/'.$name, $contents);

        return $dir.'/'.$name;
    }

    public static function pdf(string $title = 'Document'): string
    {
        return LoadDemoCommand::pdf($title, 'Contenu de test pour la recherche plein texte.');
    }

    public static function png(): string
    {
        // 1×1 transparent PNG.
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    public static function docx(): string
    {
        $path = self::file('');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Bonjour</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return (string) file_get_contents($path);
    }

    public static function zip(): string
    {
        $path = self::file('');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'hello');
        $zip->close();

        return (string) file_get_contents($path);
    }
}
