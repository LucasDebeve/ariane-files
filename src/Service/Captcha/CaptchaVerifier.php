<?php

declare(strict_types=1);

namespace App\Service\Captcha;

interface CaptchaVerifier
{
    /**
     * @return array<string, mixed> challenge sent to the widget
     */
    public function createChallenge(): array;

    public function verify(?string $payload): bool;
}
