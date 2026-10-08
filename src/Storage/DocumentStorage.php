<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * File access abstraction, so that the S3 backend (Garage) stays interchangeable.
 * Object keys are random UUIDs; original file names are never used as keys.
 */
interface DocumentStorage
{
    /**
     * Stores a local file in quarantine and returns its new key.
     */
    public function putInQuarantine(string $localPath): string;

    /**
     * Copies a quarantined object to the published area and returns its published key.
     * The caller removes the quarantined object once the database change is committed.
     */
    public function copyToPublished(string $quarantineKey): string;

    public function write(StorageArea $area, string $key, string $contents): void;

    /**
     * @return resource
     */
    public function readStream(StorageArea $area, string $key);

    public function read(StorageArea $area, string $key, ?int $maxBytes = null): string;

    public function exists(StorageArea $area, string $key): bool;

    public function delete(StorageArea $area, string $key): void;

    /**
     * Short-lived URL to fetch the object from the files.* sub-domain.
     */
    public function temporaryUrl(StorageArea $area, string $key, string $downloadName, string $mimeType, int $ttlSeconds = 60): string;
}
