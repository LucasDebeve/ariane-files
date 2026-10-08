<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\Folder;
use App\Entity\Tag;
use App\Enum\DocumentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Document>
 */
class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    public function findPublished(string $id): ?Document
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'status' => DocumentStatus::Published]);
    }

    /**
     * @return list<Document>
     */
    public function findLatestPublished(int $limit): array
    {
        /** @var list<Document> $documents */
        $documents = $this->publishedQuery()
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /**
     * @return list<Document>
     */
    public function findPublishedInFolder(?Folder $folder): array
    {
        $qb = $this->publishedQuery()->orderBy('d.title', 'ASC');
        if (null === $folder) {
            $qb->andWhere('d.folder IS NULL');
        } else {
            $qb->andWhere('d.folder = :folder')->setParameter('folder', $folder);
        }
        /** @var list<Document> $documents */
        $documents = $qb->getQuery()->getResult();

        return $documents;
    }

    /**
     * Published documents in a set of folders (used for folder ZIP archives).
     *
     * @param list<int> $folderIds
     *
     * @return list<Document>
     */
    public function findPublishedFilesInFolders(array $folderIds, int $limit): array
    {
        /** @var list<Document> $documents */
        $documents = $this->publishedQuery()
            ->andWhere('d.folder IN (:folders)')
            ->andWhere('d.storageKey IS NOT NULL')
            ->setParameter('folders', $folderIds)
            ->orderBy('d.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /**
     * Fetch published documents by id, keeping the order of the given ids.
     *
     * @param list<string> $ids
     *
     * @return list<Document>
     */
    public function findPublishedByIds(array $ids): array
    {
        $uuids = array_values(array_map(static fn (string $id): Uuid => Uuid::fromString($id), array_filter($ids, static fn (string $id): bool => Uuid::isValid($id))));
        if ([] === $uuids) {
            return [];
        }
        /** @var list<Document> $documents */
        $documents = $this->publishedQuery()
            ->andWhere('d.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $u): string => $u->toRfc4122(), $uuids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($documents as $document) {
            $byId[$document->getId()->toRfc4122()] = $document;
        }
        $ordered = [];
        foreach ($uuids as $uuid) {
            if (isset($byId[$uuid->toRfc4122()])) {
                $ordered[] = $byId[$uuid->toRfc4122()];
            }
        }

        return $ordered;
    }

    /**
     * @return list<Document>
     */
    public function findPublishedWithTag(Tag $tag, int $limit): array
    {
        /** @var list<Document> $documents */
        $documents = $this->publishedQuery()
            ->andWhere(':tag MEMBER OF d.tags')
            ->setParameter('tag', $tag)
            ->orderBy('d.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /**
     * @return list<Document>
     */
    public function findTrashed(): array
    {
        return $this->findBy(['status' => DocumentStatus::Trashed], ['deletedAt' => 'DESC']);
    }

    /**
     * @return list<Document>
     */
    public function findTrashedBefore(\DateTimeImmutable $date): array
    {
        /** @var list<Document> $documents */
        $documents = $this->createQueryBuilder('d')
            ->andWhere('d.status = :status')
            ->andWhere('d.deletedAt < :date')
            ->setParameter('status', DocumentStatus::Trashed)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();

        return $documents;
    }

    /**
     * Documents still waiting for text extraction or preview generation.
     *
     * @return list<Document>
     */
    public function findWithoutExtractedText(int $limit): array
    {
        /** @var list<Document> $documents */
        $documents = $this->publishedQuery()
            ->andWhere('d.storageKey IS NOT NULL')
            ->andWhere('d.extractedText IS NULL')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $documents;
    }

    public function countPublished(): int
    {
        return $this->count(['status' => DocumentStatus::Published]);
    }

    public function sumPublishedSize(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.size), 0)')
            ->andWhere('d.status = :status')
            ->setParameter('status', DocumentStatus::Published)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumAllStoredSize(): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COALESCE(SUM(d.size), 0)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function isStorageKeyUsed(string $key): bool
    {
        return $this->count(['storageKey' => $key]) > 0;
    }

    private function publishedQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->leftJoin('d.folder', 'f')->addSelect('f')
            ->andWhere('d.status = :status')
            ->setParameter('status', DocumentStatus::Published);
    }
}
