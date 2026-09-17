<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle\Source;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabaseUserSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'user';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `user_id`,`username`,`status`,`locale`,`updated_at_utc` '
            . 'FROM `forwext_users` WHERE `user_id`=:user_id LIMIT 1',
            ['user_id' => $documentId],
        ));
        if ($row === null || (string) ($row['status'] ?? '') !== UserStatus::Active->value) {
            return null;
        }

        $username = $row['username'] ?? null;
        $locale = $row['locale'] ?? null;
        $updated = $row['updated_at_utc'] ?? null;
        if (!is_string($username) || $username === '' || !is_string($locale) || !is_string($updated)) {
            throw new SearchException('Stored user search source is malformed.');
        }

        // Email, timezone and private/custom profile data are intentionally excluded.
        return new SearchDocument(
            $this->documentType(),
            $documentId,
            $username,
            $username,
            [SearchIndexScope::PUBLIC],
            $this->date($updated),
            $locale,
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_users', 'user_id', $afterId, $limit);
    }
}
