<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\ShareCode;
use App\Entity\User;
use App\Repository\ShareCodeRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * The common share code: verification, visitor session grant and rotation.
 * The session only stores the id of the code that was entered, so rotating the
 * code immediately revokes every previously granted visitor session.
 */
final class ShareAccess
{
    private const SESSION_KEY = 'share_access';
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly ShareCodeRepository $repository,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->repository->findActive();
    }

    public function verify(string $code): bool
    {
        $active = $this->repository->findActive();
        if (null === $active) {
            return false;
        }

        return $this->hasher()->verify($active->getCodeHash(), self::normalize($code));
    }

    public function grant(): void
    {
        $active = $this->repository->findActive();
        if (null === $active) {
            return;
        }
        $session = $this->requestStack->getSession();
        $session->migrate(true);
        $session->set(self::SESSION_KEY, $active->getId());
    }

    public function hasAccess(): bool
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->hasPreviousSession()) {
            return false;
        }
        $granted = $request->getSession()->get(self::SESSION_KEY);
        if (null === $granted) {
            return false;
        }

        return $this->repository->findActive()?->getId() === $granted;
    }

    public function revoke(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * Generates and stores a new code; returns the clear code, shown only once.
     */
    public function rotate(?User $by = null, ?string $code = null): string
    {
        $code ??= self::generate();
        $shareCode = new ShareCode($this->hasher()->hash(self::normalize($code)), $by);
        $this->entityManager->persist($shareCode);
        $this->auditLogger->log('share_code.rotated', null);
        $this->entityManager->flush();

        return $code;
    }

    public function getActive(): ?ShareCode
    {
        return $this->repository->findActive();
    }

    public static function generate(): string
    {
        $groups = [];
        for ($g = 0; $g < 3; ++$g) {
            $group = '';
            for ($i = 0; $i < 4; ++$i) {
                $group .= self::CODE_ALPHABET[random_int(0, \strlen(self::CODE_ALPHABET) - 1)];
            }
            $groups[] = $group;
        }

        return implode('-', $groups);
    }

    /**
     * Case, spaces and dashes are not significant.
     */
    public static function normalize(string $code): string
    {
        return mb_strtoupper((string) preg_replace('/[\s\-]+/u', '', $code));
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->hasherFactory->getPasswordHasher('share_code');
    }
}
