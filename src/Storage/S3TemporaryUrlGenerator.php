<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\S3ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Presigns GET requests against the public files.* endpoint. Caddy only forwards
 * signed GET/HEAD requests from that sub-domain to Garage, which stays private.
 */
final class S3TemporaryUrlGenerator implements TemporaryUrlGenerator
{
    public function __construct(
        #[Autowire(service: 'app.s3_public_client')]
        private readonly S3ClientInterface $publicClient,
        #[Autowire(env: 'S3_BUCKET_QUARANTINE')]
        private readonly string $quarantineBucket,
        #[Autowire(env: 'S3_BUCKET_PUBLISHED')]
        private readonly string $publishedBucket,
    ) {
    }

    public function generate(StorageArea $area, string $key, string $downloadName, string $mimeType, \DateTimeImmutable $expiresAt): string
    {
        $command = $this->publicClient->getCommand('GetObject', [
            'Bucket' => StorageArea::Quarantine === $area ? $this->quarantineBucket : $this->publishedBucket,
            'Key' => $key,
            'ResponseContentDisposition' => ContentDisposition::attachment($downloadName),
            'ResponseContentType' => $mimeType,
            'ResponseCacheControl' => 'private, no-store',
        ]);

        return (string) $this->publicClient->createPresignedRequest($command, $expiresAt)->getUri();
    }
}
