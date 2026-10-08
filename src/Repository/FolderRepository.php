<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Folder;
use App\Enum\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Folder>
 */
class FolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    /**
     * @return list<Folder>
     */
    public function findRoots(): array
    {
        return $this->findBy(['parent' => null], ['name' => 'ASC']);
    }

    /**
     * Every folder, ordered depth-first so that it can be rendered as an indented list.
     *
     * @return list<Folder>
     */
    public function findTree(): array
    {
        /** @var list<Folder> $all */
        $all = $this->createQueryBuilder('f')->orderBy('f.name', 'ASC')->getQuery()->getResult();
        $byParent = [];
        foreach ($all as $folder) {
            $byParent[$folder->getParent()?->getId() ?? 0][] = $folder;
        }

        $tree = [];
        $walk = static function (int $parentId) use (&$walk, &$tree, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $folder) {
                $tree[] = $folder;
                $walk((int) $folder->getId());
            }
        };
        $walk(0);

        return $tree;
    }

    /**
     * @return list<int> the folder id and all its descendants' ids
     */
    public function findSubtreeIds(Folder $folder): array
    {
        $sql = <<<'SQL'
            WITH RECURSIVE subtree AS (
                SELECT id FROM folder WHERE id = :id
                UNION ALL
                SELECT f.id FROM folder f INNER JOIN subtree s ON f.parent_id = s.id
            )
            SELECT id FROM subtree
            SQL;

        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, ['id' => $folder->getId()]));
    }

    /**
     * @return array<int, int> published document count by folder id (direct children only)
     */
    public function countDocumentsByFolder(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT folder_id, COUNT(*) FROM document WHERE status = :status AND folder_id IS NOT NULL GROUP BY folder_id',
            ['status' => DocumentStatus::Published->value],
        );

        return array_map('intval', $rows);
    }

    /**
     * @return list<Folder>
     */
    public function findMostUsed(int $limit): array
    {
        /** @var list<Folder> $folders */
        $folders = $this->createQueryBuilder('f')
            ->innerJoin('f.documents', 'd', 'WITH', 'd.status = :status')
            ->groupBy('f.id')
            ->orderBy('COUNT(d.id)', 'DESC')
            ->addOrderBy('f.name', 'ASC')
            ->setParameter('status', DocumentStatus::Published)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $folders;
    }
}
