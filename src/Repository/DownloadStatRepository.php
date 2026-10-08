<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Document;
use App\Entity\DownloadStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadStat>
 */
class DownloadStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadStat::class);
    }

    /**
     * Atomically increments today's counter and the document's total.
     */
    public function increment(Document $document, \DateTimeImmutable $day): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $id = $document->getId()->toRfc4122();
        $connection->executeStatement(
            'INSERT INTO download_stat (document_id, day, count) VALUES (:document, :day, 1)
             ON CONFLICT (document_id, day) DO UPDATE SET count = download_stat.count + 1',
            ['document' => $id, 'day' => $day->format('Y-m-d')],
        );
        $connection->executeStatement('UPDATE document SET download_count = download_count + 1 WHERE id = :document', ['document' => $id]);
    }

    /**
     * @return array<string, int> downloads per day (Y-m-d), including days without downloads
     */
    public function dailyTotals(int $days): array
    {
        $since = new \DateTimeImmutable(\sprintf('-%d days', $days - 1));
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            'SELECT day, SUM(count) FROM download_stat WHERE day >= :since GROUP BY day',
            ['since' => $since->format('Y-m-d')],
        );

        $totals = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $since->modify(\sprintf('+%d days', $i))->format('Y-m-d');
            $totals[$day] = (int) ($rows[$day] ?? 0);
        }

        return $totals;
    }

    /**
     * @return list<array{id: string, title: string, downloads: int}>
     */
    public function topDocuments(int $days, int $limit): array
    {
        $since = new \DateTimeImmutable(\sprintf('-%d days', $days - 1));
        /** @var list<array{id: string, title: string, downloads: int|string}> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT d.id, d.title, SUM(s.count) AS downloads
             FROM download_stat s INNER JOIN document d ON d.id = s.document_id
             WHERE s.day >= :since
             GROUP BY d.id, d.title
             ORDER BY downloads DESC, d.title ASC
             LIMIT :limit',
            ['since' => $since->format('Y-m-d'), 'limit' => $limit],
        );

        return array_map(static fn (array $row): array => [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'downloads' => (int) $row['downloads'],
        ], $rows);
    }
}
