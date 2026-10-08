<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use App\Repository\FolderRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Search on titles (pg_trgm, typo tolerant) and full text (French tsvector + unaccent:
 * title, description, tags and extracted content), with folder and tag filters.
 */
final class DocumentSearch
{
    private const SIMILARITY_THRESHOLD = 0.5;

    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentRepository $documents,
        private readonly FolderRepository $folders,
    ) {
    }

    public function search(SearchCriteria $criteria): SearchResult
    {
        $where = ['d.status = :status'];
        $params = ['status' => DocumentStatus::Published->value];
        $types = [];

        if (null !== $criteria->folder) {
            $where[] = 'd.folder_id IN (:folders)';
            $params['folders'] = $this->folders->findSubtreeIds($criteria->folder);
            $types['folders'] = ArrayParameterType::INTEGER;
        }
        if (null !== $criteria->tag) {
            $where[] = 'EXISTS (SELECT 1 FROM document_tag dt WHERE dt.document_id = d.id AND dt.tag_id = :tag)';
            $params['tag'] = $criteria->tag->getId();
        }

        if ($criteria->hasQuery()) {
            $params['q'] = mb_substr(trim((string) $criteria->query), 0, 200);
            $params['threshold'] = self::SIMILARITY_THRESHOLD;
            $where[] = "(d.search_vector @@ websearch_to_tsquery('ariane_fr', :q)
                OR word_similarity(unaccent(lower(:q)), unaccent(lower(d.title))) >= :threshold)";
            $score = "ts_rank_cd(d.search_vector, websearch_to_tsquery('ariane_fr', :q)) * 2
                + word_similarity(unaccent(lower(:q)), unaccent(lower(d.title)))";
            $order = 'score DESC, d.title ASC';
        } else {
            $score = '0';
            $order = 'd.created_at DESC';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM document d WHERE $whereSql", $params, $types);

        $page = max(1, $criteria->page);
        $params['limit'] = $criteria->perPage;
        $params['offset'] = ($page - 1) * $criteria->perPage;
        $ids = $this->connection->fetchFirstColumn(
            "SELECT d.id, $score AS score FROM document d WHERE $whereSql ORDER BY $order LIMIT :limit OFFSET :offset",
            $params,
            $types,
        );

        return new SearchResult($this->documents->findPublishedByIds(array_map('strval', $ids)), $total, $page, $criteria->perPage);
    }
}
