<?php

declare(strict_types=1);

namespace Forwext\Core\Search;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class NativeDatabaseSearchDriver implements SearchDriver
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function upsert(SearchDocument $document): void
    {
        $this->database->transaction(function () use ($document): void {
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO `forwext_search_documents` '
                . '(`document_key`, `document_type`, `document_id`, `title`, `body`, `locale`, `updated_at_utc`) '
                . 'VALUES (:document_key, :document_type, :document_id, :title, :body, :locale, :updated_at_utc) '
                . 'ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`), '
                . '`locale` = VALUES(`locale`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'document_key' => $document->key(),
                    'document_type' => $document->documentType,
                    'document_id' => $document->documentId,
                    'title' => $document->title,
                    'body' => $document->body,
                    'locale' => $document->locale,
                    'updated_at_utc' => self::formatDate($document->updatedAt),
                ],
            ));
            if ($affected < 0 || $affected > 2) {
                throw new SearchException('Search document upsert affected an unexpected number of rows.');
            }

            $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_search_document_scopes` WHERE `document_key` = :document_key',
                ['document_key' => $document->key()],
            ));

            foreach ($document->accessScopes as $scope) {
                $scopeAffected = $this->database->execute(new CompiledQuery(
                    'INSERT INTO `forwext_search_document_scopes` (`document_key`, `scope_token`) '
                    . 'VALUES (:document_key, :scope_token)',
                    [
                        'document_key' => $document->key(),
                        'scope_token' => $scope,
                    ],
                ));
                if ($scopeAffected !== 1) {
                    throw new SearchException('Search access-scope insertion did not affect exactly one row.');
                }
            }
        });
    }

    public function delete(string $documentType, string $documentId): bool
    {
        SearchDocument::validateIdentifier($documentType, 'document type');
        SearchDocument::validateIdentifier($documentId, 'document id');
        $key = hash('sha256', $documentType . "\0" . $documentId);

        return $this->database->transaction(function () use ($key): bool {
            $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_search_document_scopes` WHERE `document_key` = :document_key',
                ['document_key' => $key],
            ));

            return $this->database->execute(new CompiledQuery(
                'DELETE FROM `forwext_search_documents` WHERE `document_key` = :document_key',
                ['document_key' => $key],
            )) > 0;
        });
    }

    public function search(SearchQuery $query): array
    {
        $parameters = [
            'score_query' => $query->text,
            'match_query' => $query->text,
        ];
        $where = [
            'MATCH(`d`.`title`, `d`.`body`) AGAINST (:match_query IN NATURAL LANGUAGE MODE)',
        ];

        $scopePlaceholders = [];
        foreach ($query->accessScopes as $index => $scope) {
            $name = 'scope_' . $index;
            $scopePlaceholders[] = ':' . $name;
            $parameters[$name] = $scope;
        }
        $where[] = 'EXISTS (SELECT 1 FROM `forwext_search_document_scopes` `s` '
            . 'WHERE `s`.`document_key` = `d`.`document_key` '
            . 'AND `s`.`scope_token` IN (' . implode(', ', $scopePlaceholders) . '))';

        if ($query->documentTypes !== []) {
            $typePlaceholders = [];
            foreach ($query->documentTypes as $index => $type) {
                $name = 'type_' . $index;
                $typePlaceholders[] = ':' . $name;
                $parameters[$name] = $type;
            }
            $where[] = '`d`.`document_type` IN (' . implode(', ', $typePlaceholders) . ')';
        }

        if ($query->locale !== null) {
            $where[] = '`d`.`locale` = :locale';
            $parameters['locale'] = $query->locale;
        }

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `d`.`document_type`, `d`.`document_id`, '
            . 'MATCH(`d`.`title`, `d`.`body`) AGAINST (:score_query IN NATURAL LANGUAGE MODE) AS `score` '
            . 'FROM `forwext_search_documents` `d` '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY `score` DESC, `d`.`updated_at_utc` DESC '
            . 'LIMIT ' . $query->limit . ' OFFSET ' . $query->offset,
            $parameters,
        ));

        $hits = [];
        foreach ($rows as $row) {
            $type = $row['document_type'] ?? null;
            $id = $row['document_id'] ?? null;
            $score = $row['score'] ?? null;
            if (!is_string($type) || !is_string($id) || (!is_float($score) && !is_int($score) && !is_string($score))) {
                throw new SearchException('Native search returned a malformed hit.');
            }
            if (is_string($score) && !is_numeric($score)) {
                throw new SearchException('Native search returned an invalid score.');
            }
            $hits[] = new SearchHit($type, $id, (float) $score);
        }

        return $hits;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
