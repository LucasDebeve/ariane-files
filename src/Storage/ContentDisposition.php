<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ContentDisposition
{
    /**
     * Always "attachment": files are never rendered by the browser as a page.
     */
    public static function attachment(string $filename): string
    {
        $fallback = (new AsciiSlugger())->slug(pathinfo($filename, \PATHINFO_FILENAME))->toString();
        $extension = pathinfo($filename, \PATHINFO_EXTENSION);
        $fallback = ('' !== $fallback ? $fallback : 'document').('' !== $extension ? '.'.preg_replace('/[^A-Za-z0-9]/', '', $extension) : '');

        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, str_replace(['/', '\\'], '-', $filename), $fallback);
    }
}
