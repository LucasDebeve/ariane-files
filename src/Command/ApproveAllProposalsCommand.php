<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\ChangeRequestRepository;
use App\Repository\UserRepository;
use App\Service\ReviewService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:proposals:approve-all', description: 'Approves every pending proposal on behalf of a certified user.')]
final class ApproveAllProposalsCommand
{
    public function __construct(
        private readonly ChangeRequestRepository $requests,
        private readonly UserRepository $users,
        private readonly ReviewService $reviewService,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Email address of the certified user recorded as reviewer')] string $reviewer,
        #[Option(description: 'Review comment stored on every proposal')] ?string $comment = null,
        #[Option(description: 'Only list what would be approved')] bool $dryRun = false,
        #[Option(description: 'Also approve the proposals submitted by the reviewer')] bool $allowOwn = false,
    ): int {
        $user = $this->users->findOneBy(['email' => mb_strtolower(trim($reviewer))]);
        if (null === $user || !$user->isActive() || !\in_array(User::ROLE_CERTIFIED, $user->getRoles(), true)) {
            $io->error(\sprintf('"%s" is not an active certified account.', $reviewer));

            return Command::FAILURE;
        }
        $reviewerId = $user->getId();

        $pending = $this->requests->findPending();
        if ([] === $pending) {
            $io->success('No pending proposal.');

            return Command::SUCCESS;
        }

        $ids = [];
        $rows = [];
        $skipped = 0;
        foreach ($pending as $request) {
            // Same rule as the review screen: nobody validates their own proposal, unless --allow-own.
            $own = !$allowOwn && $request->getProposerUser()?->getId() === $reviewerId;
            $rows[] = [
                $request->getId()->toRfc4122(),
                $request->getType()->value,
                $request->getTitle() ?? $request->getDocument()?->getTitle() ?? '—',
                $request->getProposerName(),
                $own ? 'skipped (own proposal)' : 'to approve',
            ];
            if ($own) {
                ++$skipped;
            } else {
                $ids[] = $request->getId();
            }
        }
        $io->table(['Id', 'Type', 'Title', 'Proposer', 'Action'], $rows);

        if ($dryRun) {
            $io->note(\sprintf('Dry run: %d proposal(s) would be approved, %d skipped.', \count($ids), $skipped));

            return Command::SUCCESS;
        }

        $approved = 0;
        $failures = [];
        foreach ($ids as $id) {
            try {
                $request = $this->requests->find($id);
                $user = $this->users->find($reviewerId);
                if (null === $request || null === $user) {
                    throw new \RuntimeException('The proposal or the reviewer no longer exists.');
                }
                $this->reviewService->approve($request, $user, $comment, $allowOwn);
                ++$approved;
            } catch (\Throwable $e) {
                $failures[] = \sprintf('%s: %s', $id->toRfc4122(), $e->getMessage());
                // A failed transaction closes the entity manager: start again from a fresh one.
                $this->registry->resetManager();
            }
        }

        if ($skipped > 0) {
            $io->warning(\sprintf('%d proposal(s) skipped: the reviewer cannot validate their own proposals (see --allow-own).', $skipped));
        }
        if ([] !== $failures) {
            $io->error(array_merge([\sprintf('%d proposal(s) approved, %d failed:', $approved, \count($failures))], $failures));

            return Command::FAILURE;
        }
        $io->success(\sprintf('%d proposal(s) approved.', $approved));

        return Command::SUCCESS;
    }
}
