<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use RuntimeException;

final readonly class DatabaseAiModerationFeedbackStore implements AiModerationFeedbackStore
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function record(AiModerationFeedback $feedback): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ai_moderation_feedback '
            . '(feedback_id,decision_id,actor_user_id,kind,note,created_at_utc) '
            . 'VALUES (:feedback_id,:decision_id,:actor_user_id,:kind,:note,:created_at_utc)',
            [
                'feedback_id'=>$feedback->feedbackId->value(),
                'decision_id'=>$feedback->decisionId->value(),
                'actor_user_id'=>$feedback->actorUserId?->value(),
                'kind'=>$feedback->kind,
                'note'=>trim($feedback->note),
                'created_at_utc'=>$feedback->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('AI moderation feedback was not persisted.');
        }
    }
}
