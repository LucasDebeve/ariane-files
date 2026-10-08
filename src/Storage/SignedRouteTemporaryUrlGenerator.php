<?php

declare(strict_types=1);

namespace App\Storage;

use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Development / test replacement for S3 presigned URLs: an HMAC-signed, expiring
 * URL served by {@see \App\Controller\LocalFileController}.
 */
final class SignedRouteTemporaryUrlGenerator implements TemporaryUrlGenerator
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UriSigner $uriSigner,
    ) {
    }

    public function generate(StorageArea $area, string $key, string $downloadName, string $mimeType, \DateTimeImmutable $expiresAt): string
    {
        $url = $this->urlGenerator->generate('local_file', [
            'area' => $area->value,
            'key' => $key,
            'name' => $downloadName,
            'type' => $mimeType,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, $expiresAt);
    }
}
