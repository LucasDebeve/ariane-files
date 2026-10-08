<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use App\Enum\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /**
     * Tags used by the largest number of published documents.
     *
     * @return list<Tag>
     */
    public function findPopular(int $limit): array
    {
        $sql = <<<'SQL'
            SELECT dt.tag_id
            FROM document_tag dt
            INNER JOIN document d ON d.id = dt.document_id AND d.status = :status
            INNER JOIN tag t ON t.id = dt.tag_id
            GROUP BY dt.tag_id, t.name
            ORDER BY COUNT(*) DESC, t.name ASC
            LIMIT :limit
            SQL;
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [
            'status' => DocumentStatus::Published->value,
            'limit' => $limit,
        ]);
        if ([] === $ids) {
            return [];
        }

        $tags = [];
        foreach ($this->findBy(['id' => $ids]) as $tag) {
            $tags[$tag->getId()] = $tag;
        }

        return array_values(array_filter(array_map(static fn ($id) => $tags[(int) $id] ?? null, $ids)));
    }

    /**
     * @return list<Tag>
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
