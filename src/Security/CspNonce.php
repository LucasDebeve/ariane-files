<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-request nonce for the Content-Security-Policy (importmap and Turbo styles).
 */
final class CspNonce implements ResetInterface
{
    private ?string $nonce = null;

    public function get(): string
    {
        return $this->nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    public function isUsed(): bool
    {
        return null !== $this->nonce;
    }

    public function reset(): void
    {
        $this->nonce = null;
    }
}
