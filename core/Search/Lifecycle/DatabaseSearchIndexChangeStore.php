<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabaseSearchIndexChangeStore implements SearchIndexChangeStore
{
    private const MAX_ATTEMPTS = 20;

    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function record(string $documentType, string $documentId): void
    {
        SearchDocument::validateIdentifier($documentType, 'document type');
        SearchDocument::validateIdentifier($documentId, 'document id');
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_search_index_changes` '
            . '(`document_type`,`document_id`,`revision`,`attempts`,`available_at_utc`,`locked_until_utc`,`last_error_code`,`updated_at_utc`) '
            . 'VALUES (:document_type,:document_id,1,0,UTC_TIMESTAMP(6),NULL,NULL,UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `revision`=`revision`+1, `attempts`=0, '
            . '`available_at_utc`=UTC_TIMESTAMP(6), `locked_until_utc`=NULL, '
            . '`last_error_code`=NULL, `updated_at_utc`=UTC_TIMESTAMP(6)',
            ['document_type' => $documentType, 'document_id' => $documentId],
        ));
    }

    public function claimDue(DateTimeImmutable $now, int $limit, int $leaseSeconds = 120): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new SearchException('Search index drain limit must be between 1 and 500.');
        }
        if ($leaseSeconds < 15 || $leaseSeconds > 900) {
            throw new SearchException('Search index lease must be between 15 and 900 seconds.');
        }

        return $this->database->transaction(function () use ($now, $limit, $leaseSeconds): array {
            $rows = $this->database->fetchAll(new CompiledQuery(
                'SELECT `document_type`,`document_id`,`revision`,`attempts` '
                . 'FROM `forwext_search_index_changes` '
                . 'WHERE `available_at_utc` <= :now AND `attempts` < ' . self::MAX_ATTEMPTS . ' '
                . 'AND (`locked_until_utc` IS NULL OR `locked_until_utc` <= :now) '
                . 'ORDER BY `available_at_utc`,`document_type`,`document_id` LIMIT ' . $limit . ' FOR UPDATE',
                ['now' => self::format($now)],
            ));
            if ($rows === []) {
                return [];
            }

            $lockedUntil = self::format($now->add(new DateInterval('PT' . $leaseSeconds . 'S')));
            $changes = [];
            foreach ($rows as $row) {
                $change = self::hydrate($row);
                $affected = $this->database->execute(new CompiledQuery(
                    'UPDATE `forwext_search_index_changes` SET `locked_until_utc`=:locked_until_utc, '
                    . '`updated_at_utc`=UTC_TIMESTAMP(6) '
                    . 'WHERE `document_type`=:document_type AND `document_id`=:document_id AND `revision`=:revision',
                    [
                        'locked_until_utc' => $lockedUntil,
                        'document_type' => $change->documentType,
                        'document_id' => $change->documentId,
                        'revision' => $change->revision,
                    ],
                ));
                if ($affected !== 1) {
                    throw new SearchException('Search index change lease could not be acquired atomically.');
                }
                $changes[] = $change;
            }
            return $changes;
        });
    }

    public function acknowledge(SearchIndexChange $change): bool
    {
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_search_index_changes` '
            . 'WHERE `document_type`=:document_type AND `document_id`=:document_id AND `revision`=:revision',
            [
                'document_type' => $change->documentType,
                'document_id' => $change->documentId,
                'revision' => $change->revision,
            ],
        )) === 1;
    }

    public function retry(
        SearchIndexChange $change,
        int $attempts,
        DateTimeImmutable $availableAt,
        string $errorCode,
    ): bool {
        if ($attempts < 1 || $attempts > self::MAX_ATTEMPTS || preg_match('/^[a-z][a-z0-9_.-]{1,95}$/D', $errorCode) !== 1) {
            throw new SearchException('Search index retry metadata is invalid.');
        }

        return $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_search_index_changes` SET `attempts`=:attempts, '
            . '`available_at_utc`=:available_at_utc, `locked_until_utc`=NULL, '
            . '`last_error_code`=:last_error_code, `updated_at_utc`=UTC_TIMESTAMP(6) '
            . 'WHERE `document_type`=:document_type AND `document_id`=:document_id AND `revision`=:revision',
            [
                'attempts' => $attempts,
                'available_at_utc' => self::format($availableAt),
                'last_error_code' => $errorCode,
                'document_type' => $change->documentType,
                'document_id' => $change->documentId,
                'revision' => $change->revision,
            ],
        )) === 1;
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): SearchIndexChange
    {
        $type = $row['document_type'] ?? null;
        $id = $row['document_id'] ?? null;
        $revision = $row['revision'] ?? null;
        $attempts = $row['attempts'] ?? null;
        if (!is_string($type) || !is_string($id) || (!is_int($revision) && !is_string($revision)) || (!is_int($attempts) && !is_string($attempts))) {
            throw new SearchException('Search index change row is malformed.');
        }
        if ((is_string($revision) && !ctype_digit($revision)) || (is_string($attempts) && !ctype_digit($attempts))) {
            throw new SearchException('Search index change counters are malformed.');
        }
        return new SearchIndexChange($type, $id, (int) $revision, (int) $attempts);
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
