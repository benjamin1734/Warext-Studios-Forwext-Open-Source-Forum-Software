<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Metadata;

use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionGate;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Node\ForumNodeAuthorization;
use Forwext\Core\Forum\Node\ForumNodeHierarchy;
use Forwext\Core\Forum\Node\ForumNodeRepository;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Thread\Thread;
use Forwext\Core\Forum\Thread\ThreadRepository;
use InvalidArgumentException;

final readonly class ThreadMetadataService
{
    public function __construct(
        private ForumNodeRepository $nodes,
        private ThreadRepository $threads,
        private ForumMetadataRepository $metadata,
        private PermissionGate $gate,
    ) {
    }

    /**
     * @param list<string> $tagNames
     * @param array<string, mixed> $rawFieldValues
     */
    public function update(
        EntityId $threadId,
        ?EntityId $prefixId,
        array $tagNames,
        array $rawFieldValues,
    ): ThreadMetadata {
        [$thread, $hierarchy] = $this->threadContext($threadId);
        $forumId = $thread->forumNodeId();
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumId);
        $this->requireEdit($thread, $forumId);

        $configuration = $this->metadata->configuration($forumId);
        if ($prefixId !== null) {
            $allowed = false;
            foreach ($this->metadata->prefixesForForum($forumId) as $prefix) {
                if ($prefix->id()->equals($prefixId)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new MetadataOperationException('Selected prefix is not enabled for this forum.');
            }
        }

        $tags = array_map(
            static function (mixed $value): TagName {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Thread tags must be strings.');
                }
                return TagName::fromString($value);
            },
            $tagNames,
        );
        $draft = new ThreadMetadata($prefixId, $tags, []);
        if (!$configuration->tagsEnabled() && $draft->tags() !== []) {
            throw new MetadataOperationException('Tags are disabled for this forum.');
        }
        if (count($draft->tags()) > $configuration->maxTags()) {
            throw new MetadataOperationException('Thread tag count exceeds the forum limit.');
        }

        $definitions = [];
        foreach ($configuration->threadFieldKeys() as $fieldKey) {
            $definition = $this->metadata->fieldDefinition($fieldKey);
            if ($definition === null
                || !$definition->isEnabled()
                || $definition->target() !== CustomFieldTarget::Thread
            ) {
                throw new MetadataOperationException('Forum custom field configuration is invalid.');
            }
            $definitions[$fieldKey->value()] = $definition;
        }

        foreach ($rawFieldValues as $key => $_value) {
            if (!is_string($key) || !isset($definitions[$key])) {
                throw new MetadataOperationException('Submitted thread custom field is not enabled for this forum.');
            }
        }

        $values = [];
        foreach ($definitions as $key => $definition) {
            if (!array_key_exists($key, $rawFieldValues)) {
                if ($definition->isRequired()) {
                    throw new MetadataOperationException('A required thread custom field is missing.');
                }
                continue;
            }
            $raw = $rawFieldValues[$key];
            if ($definition->isRequired() && is_string($raw) && trim($raw) === '') {
                throw new MetadataOperationException('A required thread custom field cannot be empty.');
            }
            $values[$key] = $definition->validate($raw);
        }

        $result = new ThreadMetadata($prefixId, $tags, $values);
        $this->metadata->replaceThreadMetadata(
            $threadId,
            $result,
            $configuration->allowNewTags(),
        );
        return $result;
    }

    /** @return list<Tag> */
    public function autocompleteTags(EntityId $forumNodeId, string $query, int $limit = 10): array
    {
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($forumNodeId);
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new MetadataOperationException('Tag target is not an available forum.');
        }
        (new ForumNodeAuthorization($this->gate))->requireView($hierarchy, $forumNodeId);
        if (!$this->metadata->configuration($forumNodeId)->tagsEnabled()) {
            return [];
        }
        return $this->metadata->autocompleteTags($query, $limit);
    }

    /** @return array{0:Thread,1:ForumNodeHierarchy} */
    private function threadContext(EntityId $threadId): array
    {
        $thread = $this->threads->find($threadId)
            ?? throw new MetadataOperationException('Thread is not available.');
        $hierarchy = new ForumNodeHierarchy($this->nodes->all());
        $forum = $hierarchy->find($thread->forumNodeId());
        if ($forum === null || $forum->type() !== ForumNodeType::Forum) {
            throw new MetadataOperationException('Thread forum is not available.');
        }
        return [$thread, $hierarchy];
    }

    private function requireEdit(Thread $thread, EntityId $forumNodeId): void
    {
        $author = $thread->authorUserId();
        if ($author !== null && $author->equals($this->gate->actorId())) {
            try {
                $this->gate->require(ThreadMetadataPermission::EditOwn->key(), $forumNodeId);
                return;
            } catch (PermissionDeniedException) {
                // Explicit edit-any authority may still authorize staff.
            }
        }
        $this->gate->require(ThreadMetadataPermission::EditAny->key(), $forumNodeId);
    }
}
