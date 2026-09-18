<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseAiModerationOverrideRepository implements AiModerationOverrideRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function active(string $contentFingerprint, DateTimeImmutable $at): ?AiModerationHumanOverride
    {
        AiModerationFingerprint::assert($contentFingerprint);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT content_fingerprint,action,actor_user_id,reason,created_at_utc,expires_at_utc '
            . 'FROM forwext_ai_moderation_overrides '
            . 'WHERE content_fingerprint=:content_fingerprint '
            . 'AND (expires_at_utc IS NULL OR expires_at_utc>:at) LIMIT 1',
            ['content_fingerprint'=>$contentFingerprint,'at'=>$this->format($at)],
        ));
        if ($row === null) {
            return null;
        }

        try {
            $action = AiModerationAction::from((string) $row['action']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored AI moderation override action is invalid.', previous: $exception);
        }

        return new AiModerationHumanOverride(
            (string) $row['content_fingerprint'],
            $action,
            ($row['actor_user_id'] ?? null) === null ? null : UserId::fromStored((string) $row['actor_user_id']),
            (string) $row['reason'],
            $this->parse((string) $row['created_at_utc']),
            ($row['expires_at_utc'] ?? null) === null ? null : $this->parse((string) $row['expires_at_utc']),
        );
    }

    public function save(AiModerationHumanOverride $override): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_ai_moderation_overrides '
            . '(content_fingerprint,action,actor_user_id,reason,created_at_utc,expires_at_utc) '
            . 'VALUES (:content_fingerprint,:action,:actor_user_id,:reason,:created_at_utc,:expires_at_utc) '
            . 'ON DUPLICATE KEY UPDATE action=VALUES(action),actor_user_id=VALUES(actor_user_id),'
            . 'reason=VALUES(reason),created_at_utc=VALUES(created_at_utc),expires_at_utc=VALUES(expires_at_utc)',
            [
                'content_fingerprint'=>$override->contentFingerprint,
                'action'=>$override->action->value,
                'actor_user_id'=>$override->actorUserId?->value(),
                'reason'=>$override->reason,
                'created_at_utc'=>$this->format($override->createdAt),
                'expires_at_utc'=>$override->expiresAt === null ? null : $this->format($override->expiresAt),
            ],
            true,
        ));
    }

    public function delete(string $contentFingerprint): bool
    {
        AiModerationFingerprint::assert($contentFingerprint);
        return $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_ai_moderation_overrides WHERE content_fingerprint=:content_fingerprint',
            ['content_fingerprint'=>$contentFingerprint],
            true,
        )) === 1;
    }

    private function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private function parse(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$parsed instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored AI moderation override timestamp is invalid.');
        }
        return $parsed;
    }
}
