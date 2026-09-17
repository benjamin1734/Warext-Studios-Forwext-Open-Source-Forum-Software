<?php

declare(strict_types=1);

namespace Forwext\Core\Search\Lifecycle\Source;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Post\PostModerationState;
use Forwext\Core\Forum\Thread\ThreadModerationState;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabasePostSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'post';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT p.`post_id`,p.`body_source`,p.`moderation_state`,p.`deleted`,p.`updated_at_utc`,'
            . 't.`title` AS `thread_title`,t.`forum_node_id`,t.`moderation_state` AS `thread_state`,'
            . 't.`deleted` AS `thread_deleted`,t.`merged_into_thread_id`,n.`visibility` AS `node_visibility` '
            . 'FROM `forwext_posts` p INNER JOIN `forwext_threads` t ON t.`thread_id`=p.`thread_id` '
            . 'INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . 'WHERE p.`post_id`=:post_id LIMIT 1',
            ['post_id' => $documentId],
        ));
        if (
            $row === null
            || (bool) ($row['deleted'] ?? false)
            || (string) ($row['moderation_state'] ?? '') !== PostModerationState::Visible->value
            || (bool) ($row['thread_deleted'] ?? false)
            || ($row['merged_into_thread_id'] ?? null) !== null
            || (string) ($row['thread_state'] ?? '') !== ThreadModerationState::Visible->value
            || (string) ($row['node_visibility'] ?? '') === ForumNodeVisibility::Disabled->value
        ) {
            return null;
        }
        foreach (['body_source', 'thread_title', 'forum_node_id', 'updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new SearchException('Stored post search source is malformed.');
            }
        }

        return new SearchDocument(
            $this->documentType(),
            $documentId,
            (string) $row['thread_title'],
            (string) $row['body_source'],
            [SearchIndexScope::forumNode(EntityId::fromString((string) $row['forum_node_id']))],
            $this->date((string) $row['updated_at_utc']),
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_posts', 'post_id', $afterId, $limit);
    }
}
