<?php

declare(strict_types=1);

namespace App\Service\Captcha;

/**
 * Test environment only.
 */
final class AlwaysValidCaptchaVerifier implements CaptchaVerifier
{
    public function createChallenge(): array
    {
        return [];
    }

    public function verify(?string $payload): bool
    {
        return true;
    }
}
