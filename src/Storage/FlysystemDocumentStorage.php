<?php

declare(strict_types=1);

namespace App\Storage;

use League\Flysystem\FilesystemOperator;
use Symfony\Component\Uid\Uuid;

final class FlysystemDocumentStorage implements DocumentStorage
{
    public function __construct(
        private readonly FilesystemOperator $quarantineStorage,
        private readonly FilesystemOperator $publishedStorage,
        private readonly TemporaryUrlGenerator $urlGenerator,
    ) {
    }

    public function putInQuarantine(string $localPath): string
    {
        $key = Uuid::v4()->toRfc4122();
        $stream = fopen($localPath, 'r');
        if (false === $stream) {
            throw new \RuntimeException('Unable to read the uploaded file.');
        }
        try {
            $this->quarantineStorage->writeStream($key, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $key;
    }

    public function copyToPublished(string $quarantineKey): string
    {
        $key = Uuid::v4()->toRfc4122();
        $stream = $this->quarantineStorage->readStream($quarantineKey);
        try {
            $this->publishedStorage->writeStream($key, $stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        return $key;
    }

    public function write(StorageArea $area, string $key, string $contents): void
    {
        $this->filesystem($area)->write($key, $contents);
    }

    public function readStream(StorageArea $area, string $key)
    {
        return $this->filesystem($area)->readStream($key);
    }

    public function read(StorageArea $area, string $key, ?int $maxBytes = null): string
    {
        if (null === $maxBytes) {
            return $this->filesystem($area)->read($key);
        }
        $stream = $this->readStream($area, $key);
        try {
            return (string) stream_get_contents($stream, $maxBytes);
        } finally {
            fclose($stream);
        }
    }

    public function exists(StorageArea $area, string $key): bool
    {
        return $this->filesystem($area)->fileExists($key);
    }

    public function delete(StorageArea $area, string $key): void
    {
        $this->filesystem($area)->delete($key);
    }

    public function temporaryUrl(StorageArea $area, string $key, string $downloadName, string $mimeType, int $ttlSeconds = 60): string
    {
        return $this->urlGenerator->generate($area, $key, $downloadName, $mimeType, new \DateTimeImmutable(\sprintf('+%d seconds', $ttlSeconds)));
    }

    private function filesystem(StorageArea $area): FilesystemOperator
    {
        return match ($area) {
            StorageArea::Quarantine => $this->quarantineStorage,
            StorageArea::Published => $this->publishedStorage,
        };
    }
}
