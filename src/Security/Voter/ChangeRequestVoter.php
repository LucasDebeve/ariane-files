<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\ChangeRequest;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * REVIEW: an active certified user with 2FA enabled, who is not the proposer,
 * may approve or reject a pending change request.
 *
 * @extends Voter<string, ChangeRequest>
 */
final class ChangeRequestVoter extends Voter
{
    public const VIEW = 'CHANGE_REQUEST_VIEW';
    public const REVIEW = 'CHANGE_REQUEST_REVIEW';

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ChangeRequest && \in_array($attribute, [self::VIEW, self::REVIEW], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive() || !$user->isTotpAuthenticationEnabled()) {
            return false;
        }
        if (!$this->decisionManager->decide($token, [User::ROLE_CERTIFIED])) {
            return false;
        }
        if (self::VIEW === $attribute) {
            return true;
        }
        if (!$subject->isPending()) {
            $vote?->addReason('already reviewed');

            return false;
        }
        $proposer = $subject->getProposerUser();
        if (null !== $proposer && $proposer->getId() === $user->getId()) {
            $vote?->addReason('own proposal');

            return false;
        }

        return true;
    }
}
