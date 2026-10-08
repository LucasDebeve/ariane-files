<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\ShareAccess;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * SHARE_ACCESS: browsing, searching, previewing, downloading and proposing changes.
 * Granted to visitors holding the current share code, and to active certified users.
 *
 * @extends Voter<string, mixed>
 */
final class AccessVoter extends Voter
{
    public const SHARE_ACCESS = 'SHARE_ACCESS';

    public function __construct(private readonly ShareAccess $shareAccess)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::SHARE_ACCESS === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if ($user instanceof User && $user->isActive()) {
            return true;
        }

        return $this->shareAccess->hasAccess();
    }
}
