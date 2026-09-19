<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabaseAiModerationForumPolicyRepository implements AiModerationForumPolicyRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function find(EntityId $forumNodeId): ?AiModerationForumPolicy
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT enabled,provider_key,prompt_version,redact_sensitive_data,flag_threshold,queue_threshold,'
            . 'reject_threshold,input_cost_micros_per_million,output_cost_micros_per_million '
            . 'FROM forwext_ai_moderation_forum_policies WHERE node_id=:node_id LIMIT 1',
            ['node_id'=>$forumNodeId->value()],
        ));
        if ($row === null) {
            return null;
        }
        return new AiModerationForumPolicy(
            $forumNodeId,
            (bool) $row['enabled'],
            (string) $row['provider_key'],
            (string) $row['prompt_version'],
            (bool) $row['redact_sensitive_data'],
            (float) $row['flag_threshold'],
            (float) $row['queue_threshold'],
            (float) $row['reject_threshold'],
            (int) $row['input_cost_micros_per_million'],
            (int) $row['output_cost_micros_per_million'],
        );
    }
}
