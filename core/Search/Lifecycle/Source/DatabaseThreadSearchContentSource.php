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
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;

final readonly class DatabaseThreadSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string { return 'thread'; }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT t.`thread_id`,t.`forum_node_id`,t.`author_user_id`,t.`type_key`,t.`title`,t.`moderation_state`,t.`deleted`,t.`archived`,'
            . 't.`merged_into_thread_id`,t.`updated_at_utc`,n.`visibility` AS `node_visibility` '
            . 'FROM `forwext_threads` t INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . 'WHERE t.`thread_id`=:thread_id LIMIT 1', ['thread_id' => $documentId],
        ));
        if ($row === null || (bool) ($row['deleted'] ?? false) || (bool) ($row['archived'] ?? false) || ($row['merged_into_thread_id'] ?? null) !== null
            || (string) ($row['moderation_state'] ?? '') !== ThreadModerationState::Visible->value
            || (string) ($row['node_visibility'] ?? '') === ForumNodeVisibility::Disabled->value) return null;
        foreach (['forum_node_id','type_key','title','updated_at_utc'] as $key) {
            if (!is_string($row[$key] ?? null) || $row[$key] === '') throw new SearchException('Stored thread search source is malformed.');
        }
        $attributes = [
            SearchAttribute::FORUM => [(string) $row['forum_node_id']],
            SearchAttribute::STATE => [ThreadModerationState::Visible->value],
            SearchAttribute::THREAD_TYPE => [(string) $row['type_key']],
        ];
        if (is_string($row['author_user_id'] ?? null) && $row['author_user_id'] !== '') $attributes[SearchAttribute::USER] = [(string) $row['author_user_id']];
        $prefix = $this->database->fetchOne(new CompiledQuery(
            'SELECT `prefix_id` FROM `forwext_thread_prefix_assignments` WHERE `thread_id`=:thread_id LIMIT 1',
            ['thread_id' => $documentId],
        ));
        if (is_string($prefix['prefix_id'] ?? null) && $prefix['prefix_id'] !== '') $attributes[SearchAttribute::PREFIX] = [(string) $prefix['prefix_id']];
        $attributes[SearchAttribute::TAG] = $this->tagIds($documentId);

        return new SearchDocument(
            $this->documentType(), $documentId, (string) $row['title'], '',
            [SearchIndexScope::forumNode(EntityId::fromString((string) $row['forum_node_id']))],
            $this->date((string) $row['updated_at_utc']), null, $attributes,
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage { return $this->scanIds('forwext_threads', 'thread_id', $afterId, $limit); }

    /** @return list<string> */
    private function tagIds(string $threadId): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `tag_id` FROM `forwext_thread_tags` WHERE `thread_id`=:thread_id ORDER BY `tag_id` ASC', ['thread_id' => $threadId],
        ));
        $ids = [];
        foreach ($rows as $row) {
            $id = $row['tag_id'] ?? null;
            if (!is_string($id) || $id === '') throw new SearchException('Stored thread search tag is malformed.');
            $ids[] = $id;
        }
        return $ids;
    }
}
