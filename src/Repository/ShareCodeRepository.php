<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ShareCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ShareCode>
 */
class ShareCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareCode::class);
    }

    public function findActive(): ?ShareCode
    {
        return $this->findOneBy([], ['id' => 'DESC']);
    }
}
