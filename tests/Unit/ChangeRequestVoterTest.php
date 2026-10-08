<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\ChangeRequest;
use App\Entity\User;
use App\Enum\ChangeRequestType;
use App\Security\Voter\ChangeRequestVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ChangeRequestVoterTest extends TestCase
{
    private ChangeRequestVoter $voter;

    protected function setUp(): void
    {
        $decisionManager = $this->createStub(AccessDecisionManagerInterface::class);
        $decisionManager->method('decide')->willReturnCallback(
            static fn ($token, array $attributes): bool => \in_array($attributes[0], $token->getRoleNames(), true),
        );
        $this->voter = new ChangeRequestVoter($decisionManager);
    }

    public function testActiveCertifiedUserWith2faCanReview(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->certified(1), $this->request()));
    }

    public function testCannotReviewOwnProposal(): void
    {
        $user = $this->certified(1);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, $this->request($user)));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote($this->certified(2), $this->request($user)));
    }

    public function testCannotReviewWithout2fa(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->certified(1)->setTotpSecret(null), $this->request()));
    }

    public function testCannotReviewTwice(): void
    {
        $request = $this->request();
        $request->reject($this->certified(3), 'Doublon');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($this->certified(1), $request));
    }

    public function testUserWithoutCertifiedRoleCannotReview(): void
    {
        $user = $this->certified(1)->setRoles([]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote($user, $this->request()));
    }

    private function vote(User $user, ChangeRequest $request): int
    {
        return $this->voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), $request, [ChangeRequestVoter::REVIEW]);
    }

    private function certified(int $id): User
    {
        $user = (new User())->setEmail("user$id@example.org")->setDisplayName("User $id")->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->approve();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function request(?User $proposer = null): ChangeRequest
    {
        return new ChangeRequest(ChangeRequestType::Addition, 'Camille', ['title' => 'Test'], null, $proposer);
    }
}
