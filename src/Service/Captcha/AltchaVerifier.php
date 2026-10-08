<?php

declare(strict_types=1);

namespace App\Service\Captcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Psr\Cache\CacheItemPoolInterface;

/**
 * ALTCHA proof-of-work captcha: open source, no third party, no tracking.
 * Solved payloads are remembered until expiry so they cannot be replayed.
 */
final class AltchaVerifier implements CaptchaVerifier
{
    private const TTL = 600;

    private readonly Altcha $altcha;
    private readonly Pbkdf2 $algorithm;

    public function __construct(
        string $hmacKey,
        private readonly CacheItemPoolInterface $cache,
    ) {
        $this->altcha = new Altcha(hmacSignatureSecret: $hmacKey, hmacKeySignatureSecret: hash('sha256', 'key-'.$hmacKey));
        $this->algorithm = new Pbkdf2();
    }

    public function createChallenge(): array
    {
        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm,
            cost: 1000,
            counter: random_int(1000, 3000),
            expiresAt: time() + self::TTL,
        ))->toArray();
    }

    public function verify(?string $payload): bool
    {
        if (null === $payload || '' === $payload || \strlen($payload) > 10000) {
            return false;
        }

        $item = $this->cache->getItem('altcha_'.hash('sha256', $payload));
        if ($item->isHit()) {
            return false;
        }

        try {
            $result = $this->altcha->verifySolution(new VerifySolutionOptions(algorithm: $this->algorithm, payload: $payload));
        } catch (\Throwable) {
            return false;
        }
        if (!$result->verified) {
            return false;
        }

        $this->cache->save($item->set(true)->expiresAfter(self::TTL));

        return true;
    }
}
