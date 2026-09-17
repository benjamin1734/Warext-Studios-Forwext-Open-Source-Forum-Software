<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle\Source;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabaseForumSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'forum';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `node_id`,`title`,`description`,`page_content`,`visibility`,`updated_at_utc` '
            . 'FROM `forwext_nodes` WHERE `node_id`=:node_id LIMIT 1',
            ['node_id' => $documentId],
        ));
        if ($row === null || (string) ($row['visibility'] ?? '') === ForumNodeVisibility::Disabled->value) {
            return null;
        }

        foreach (['title', 'description', 'updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new SearchException('Stored forum search source is malformed.');
            }
        }
        $body = trim((string) $row['description'] . "\n" . (string) ($row['page_content'] ?? ''));

        return new SearchDocument(
            $this->documentType(),
            $documentId,
            (string) $row['title'],
            $body,
            [SearchIndexScope::forumNode(EntityId::fromString($documentId))],
            $this->date((string) $row['updated_at_utc']),
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_nodes', 'node_id', $afterId, $limit);
    }
}
