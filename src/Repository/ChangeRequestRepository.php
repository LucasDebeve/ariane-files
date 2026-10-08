<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChangeRequest;
use App\Entity\Document;
use App\Enum\ChangeRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ChangeRequest>
 */
class ChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeRequest::class);
    }

    public function findByPublicId(string $id): ?ChangeRequest
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /**
     * @return list<ChangeRequest>
     */
    public function findPending(): array
    {
        /** @var list<ChangeRequest> $requests */
        $requests = $this->createQueryBuilder('c')
            ->leftJoin('c.document', 'd')->addSelect('d')
            ->andWhere('c.status = :status')
            ->setParameter('status', ChangeRequestStatus::Pending)
            ->orderBy('c.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $requests;
    }

    public function countPending(): int
    {
        return $this->count(['status' => ChangeRequestStatus::Pending]);
    }

    public function countPendingFor(Document $document): int
    {
        return $this->count(['status' => ChangeRequestStatus::Pending, 'document' => $document]);
    }

    /**
     * @return list<ChangeRequest>
     */
    public function findReviewed(int $limit): array
    {
        /** @var list<ChangeRequest> $requests */
        $requests = $this->createQueryBuilder('c')
            ->leftJoin('c.document', 'd')->addSelect('d')
            ->leftJoin('c.reviewer', 'r')->addSelect('r')
            ->andWhere('c.status != :status')
            ->setParameter('status', ChangeRequestStatus::Pending)
            ->orderBy('c.reviewedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $requests;
    }

    /** Total bytes currently held in the quarantine bucket. */
    public function sumQuarantineSize(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.quarantineSize), 0)')
            ->andWhere('c.quarantineKey IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Rejected requests whose quarantined file must be purged.
     *
     * @return list<ChangeRequest>
     */
    public function findRejectedWithFileBefore(\DateTimeImmutable $date): array
    {
        /** @var list<ChangeRequest> $requests */
        $requests = $this->createQueryBuilder('c')
            ->andWhere('c.status = :status')
            ->andWhere('c.quarantineKey IS NOT NULL')
            ->andWhere('c.reviewedAt < :date')
            ->setParameter('status', ChangeRequestStatus::Rejected)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();

        return $requests;
    }
}
