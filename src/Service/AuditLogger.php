<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Records sensitive actions. The entry is persisted but not flushed:
 * it is written in the same transaction as the action it describes.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, scalar|null> $details
     */
    public function log(string $action, ?object $target = null, array $details = [], ?string $visitorName = null): void
    {
        $user = $this->security->getUser();
        $actor = $user instanceof User ? $user : null;
        $label = match (true) {
            null !== $actor => \sprintf('%s <%s>', $actor->getDisplayName(), $actor->getEmail()),
            null !== $visitorName => \sprintf('Visiteur « %s »', $visitorName),
            default => 'Système',
        };

        [$targetType, $targetId] = $this->describe($target);
        $this->entityManager->persist(new AuditLog($actor, $label, $action, $targetType, $targetId, $details));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function describe(?object $target): array
    {
        if (null === $target) {
            return [null, null];
        }
        $type = (new \ReflectionClass($target))->getShortName();
        $id = method_exists($target, 'getId') ? $target->getId() : null;

        return [$type, null === $id ? null : (string) $id];
    }
}
