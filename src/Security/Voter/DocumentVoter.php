<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Document>
 */
final class DocumentVoter extends Voter
{
    public const VIEW = 'DOCUMENT_VIEW';
    public const PROPOSE_CHANGE = 'DOCUMENT_PROPOSE_CHANGE';
    public const MANAGE_TRASH = 'DOCUMENT_MANAGE_TRASH';

    public function __construct(private readonly Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Document && \in_array($attribute, [self::VIEW, self::PROPOSE_CHANGE, self::MANAGE_TRASH], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        return match ($attribute) {
            self::VIEW, self::PROPOSE_CHANGE => $subject->isPublished() && $this->security->isGranted(AccessVoter::SHARE_ACCESS),
            self::MANAGE_TRASH => !$subject->isPublished() && $this->security->isGranted('ROLE_ADMIN'),
            default => false,
        };
    }
}
