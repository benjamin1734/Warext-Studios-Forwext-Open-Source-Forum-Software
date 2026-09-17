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
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabasePostSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string { return 'post'; }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT p.`post_id`,p.`author_user_id`,p.`body_source`,p.`moderation_state`,p.`deleted`,p.`updated_at_utc`,'
            . 't.`thread_id`,t.`title` AS `thread_title`,t.`forum_node_id`,t.`type_key`,t.`moderation_state` AS `thread_state`,'
            . 't.`deleted` AS `thread_deleted`,t.`merged_into_thread_id`,n.`visibility` AS `node_visibility` '
            . 'FROM `forwext_posts` p INNER JOIN `forwext_threads` t ON t.`thread_id`=p.`thread_id` '
            . 'INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` WHERE p.`post_id`=:post_id LIMIT 1',
            ['post_id' => $documentId],
        ));
        if ($row === null || (bool) ($row['deleted'] ?? false)
            || (string) ($row['moderation_state'] ?? '') !== PostModerationState::Visible->value
            || (bool) ($row['thread_deleted'] ?? false) || ($row['merged_into_thread_id'] ?? null) !== null
            || (string) ($row['thread_state'] ?? '') !== ThreadModerationState::Visible->value
            || (string) ($row['node_visibility'] ?? '') === ForumNodeVisibility::Disabled->value) return null;
        foreach (['body_source','thread_title','forum_node_id','updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null)) throw new SearchException('Stored post search source is malformed.');
        }
        $attributes = [
            SearchAttribute::FORUM => [(string) $row['forum_node_id']],
            SearchAttribute::STATE => [PostModerationState::Visible->value],
        ];
        if (is_string($row['type_key'] ?? null) && $row['type_key'] !== '') $attributes[SearchAttribute::THREAD_TYPE] = [(string) $row['type_key']];
        if (is_string($row['author_user_id'] ?? null) && $row['author_user_id'] !== '') $attributes[SearchAttribute::USER] = [(string) $row['author_user_id']];
        $threadId = is_string($row['thread_id'] ?? null) ? (string) $row['thread_id'] : '';
        $prefix = $threadId !== '' ? $this->database->fetchOne(new CompiledQuery(
            'SELECT `prefix_id` FROM `forwext_thread_prefix_assignments` WHERE `thread_id`=:thread_id LIMIT 1', ['thread_id' => $threadId],
        )) : null;
        if (is_string($prefix['prefix_id'] ?? null) && $prefix['prefix_id'] !== '') $attributes[SearchAttribute::PREFIX] = [(string) $prefix['prefix_id']];
        $tagRows = $threadId !== '' ? $this->database->fetchAll(new CompiledQuery(
            'SELECT `tag_id` FROM `forwext_thread_tags` WHERE `thread_id`=:thread_id ORDER BY `tag_id` ASC', ['thread_id' => $threadId],
        )) : [];
        $tags = [];
        foreach ($tagRows as $tagRow) {
            $tagId = $tagRow['tag_id'] ?? null;
            if (!is_string($tagId) || $tagId === '') throw new SearchException('Stored post search tag is malformed.');
            $tags[] = $tagId;
        }
        $attributes[SearchAttribute::TAG] = $tags;

        return new SearchDocument(
            $this->documentType(), $documentId, (string) $row['thread_title'], (string) $row['body_source'],
            [SearchIndexScope::forumNode(EntityId::fromString((string) $row['forum_node_id']))],
            $this->date((string) $row['updated_at_utc']), null, $attributes,
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage { return $this->scanIds('forwext_posts', 'post_id', $afterId, $limit); }
}
