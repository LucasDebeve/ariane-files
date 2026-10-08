<?php

declare(strict_types=1);

namespace App\Service\Upload;

/**
 * White list of accepted formats: extension => canonical MIME type.
 * SVG is deliberately excluded (it can carry scripts).
 */
final class FileType
{
    public const ALLOWED = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'txt' => 'text/plain',
    ];

    /** Formats converted to PDF (Gotenberg) for the in-browser preview. */
    public const OFFICE = ['doc', 'docx', 'odt', 'ppt', 'pptx', 'odp', 'xlsx', 'ods'];

    public static function isAllowedExtension(string $extension): bool
    {
        return isset(self::ALLOWED[mb_strtolower($extension)]);
    }

    public static function acceptAttribute(): string
    {
        return implode(',', array_map(static fn (string $ext): string => '.'.$ext, array_keys(self::ALLOWED)));
    }

    public static function isOffice(?string $mimeType): bool
    {
        foreach (self::OFFICE as $extension) {
            if (self::ALLOWED[$extension] === $mimeType) {
                return true;
            }
        }

        return false;
    }

    public static function isImage(?string $mimeType): bool
    {
        return null !== $mimeType && \in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /**
     * Short format label used by the UI icons ("pdf", "doc", "ppt", "xls", "img", "txt").
     */
    public static function family(?string $mimeType): string
    {
        return match (true) {
            null === $mimeType => 'video',
            'application/pdf' === $mimeType => 'pdf',
            \in_array($mimeType, [self::ALLOWED['doc'], self::ALLOWED['docx'], self::ALLOWED['odt']], true) => 'doc',
            \in_array($mimeType, [self::ALLOWED['ppt'], self::ALLOWED['pptx'], self::ALLOWED['odp']], true) => 'ppt',
            \in_array($mimeType, [self::ALLOWED['xlsx'], self::ALLOWED['ods']], true) => 'xls',
            self::isImage($mimeType) => 'img',
            default => 'txt',
        };
    }
}
