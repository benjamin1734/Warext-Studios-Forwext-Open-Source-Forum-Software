<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle\Source;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabaseThreadSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'thread';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT t.`thread_id`,t.`forum_node_id`,t.`title`,t.`moderation_state`,t.`deleted`,'
            . 't.`merged_into_thread_id`,t.`updated_at_utc`,n.`visibility` AS `node_visibility` '
            . 'FROM `forwext_threads` t INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . 'WHERE t.`thread_id`=:thread_id LIMIT 1',
            ['thread_id' => $documentId],
        ));
        if (
            $row === null
            || (bool) ($row['deleted'] ?? false)
            || ($row['merged_into_thread_id'] ?? null) !== null
            || (string) ($row['moderation_state'] ?? '') !== ThreadModerationState::Visible->value
            || (string) ($row['node_visibility'] ?? '') === ForumNodeVisibility::Disabled->value
        ) {
            return null;
        }
        foreach (['forum_node_id', 'title', 'updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null) || $row[$key] === '') {
                throw new SearchException('Stored thread search source is malformed.');
            }
        }

        return new SearchDocument(
            $this->documentType(),
            $documentId,
            (string) $row['title'],
            '',
            [SearchIndexScope::forumNode(EntityId::fromString((string) $row['forum_node_id']))],
            $this->date((string) $row['updated_at_utc']),
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_threads', 'thread_id', $afterId, $limit);
    }
}
