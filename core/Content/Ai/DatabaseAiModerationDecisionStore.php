<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use RuntimeException;

final readonly class DatabaseAiModerationDecisionStore implements AiModerationDecisionStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function record(AiModerationDecisionRecord $record): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ai_moderation_decisions '
            . '(decision_id,content_fingerprint,provider_key,model,risk_score,action,fallback_reason,'
            . 'human_override,target_type,target_id,created_at_utc) '
            . 'VALUES (:decision_id,:content_fingerprint,:provider_key,:model,:risk_score,:action,:fallback_reason,'
            . ':human_override,:target_type,:target_id,:created_at_utc)',
            [
                'decision_id'=>$record->decisionId->value(),
                'content_fingerprint'=>$record->contentFingerprint,
                'provider_key'=>$record->providerKey,
                'model'=>$record->model,
                'risk_score'=>$record->riskScore,
                'action'=>$record->action->value,
                'fallback_reason'=>$record->fallbackReason,
                'human_override'=>$record->humanOverride,
                'target_type'=>$record->targetType,
                'target_id'=>$record->targetId?->value(),
                'created_at_utc'=>$this->format($record->createdAt),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('AI moderation decision was not persisted.');
        }
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
