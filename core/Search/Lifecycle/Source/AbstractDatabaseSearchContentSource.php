<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle\Source;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

abstract readonly class AbstractDatabaseSearchContentSource
{
    public function __construct(protected QueryExecutor $database)
    {
    }

    protected function validateId(string $documentId): void
    {
        SearchDocument::validateIdentifier($documentId, 'document id');
    }

    protected function scanIds(
        string $table,
        string $idColumn,
        ?string $afterId,
        int $limit,
    ): SearchContentPage {
        if ($limit < 1 || $limit > 500) {
            throw new SearchException('Search source scan limit must be between 1 and 500.');
        }
        if ($afterId !== null) {
            $this->validateId($afterId);
        }

        $parameters = [];
        $where = '';
        if ($afterId !== null) {
            $where = ' WHERE `' . $idColumn . '` > :after_id';
            $parameters['after_id'] = $afterId;
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `' . $idColumn . '` AS `document_id` FROM `' . $table . '`'
            . $where . ' ORDER BY `' . $idColumn . '` ASC LIMIT ' . ($limit + 1),
            $parameters,
        ));
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $ids = [];
        foreach ($rows as $row) {
            $id = $row['document_id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new SearchException('Search source scan returned an invalid document id.');
            }
            $this->validateId($id);
            $ids[] = $id;
        }

        return new SearchContentPage(
            $ids,
            $hasMore && $ids !== [] ? $ids[array_key_last($ids)] : null,
        );
    }

    protected function date(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }
        throw new SearchException('Search source timestamp is invalid.');
    }
}
