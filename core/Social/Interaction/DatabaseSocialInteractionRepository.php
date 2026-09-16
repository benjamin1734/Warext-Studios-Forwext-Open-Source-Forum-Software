<?php

declare(strict_types=1);

namespace Forwext\Core\Social\Interaction;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Forum\Post\PostId;

final readonly class DatabaseSocialInteractionRepository implements SocialInteractionRepository
{
    public function __construct(private TransactionalQueryExecutor $database) {}

    public function reactionType(string $key): ?ReactionType
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `reaction_key`, `label`, `score` FROM `forwext_reaction_types` '
            . 'WHERE `reaction_key` = :reaction_key AND `enabled` = 1 LIMIT 1',
            ['reaction_key' => $key],
        ));
        return $row === null ? null : new ReactionType((string) $row['reaction_key'], (string) $row['label'], (int) $row['score']);
    }

    public function setReaction(EntityId $actorId, EntityId $postId, string $reactionKey): void
    {
        UserId::assert($actorId); PostId::assert($postId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_post_reactions` (`post_id`, `user_id`, `reaction_key`, `created_at_utc`, `updated_at_utc`) '
            . 'VALUES (:post_id, :user_id, :reaction_key, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `reaction_key` = VALUES(`reaction_key`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            ['post_id' => $postId->value(), 'user_id' => $actorId->value(), 'reaction_key' => $reactionKey],
        ));
    }

    public function removeReaction(EntityId $actorId, EntityId $postId): void
    {
        UserId::assert($actorId); PostId::assert($postId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_post_reactions` WHERE `post_id` = :post_id AND `user_id` = :user_id',
            ['post_id' => $postId->value(), 'user_id' => $actorId->value()],
        ));
    }

    public function reactionSummary(EntityId $postId): ReactionSummary
    {
        PostId::assert($postId);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT r.`reaction_key`, COUNT(*) AS `reaction_count`, SUM(rt.`score`) AS `reaction_score` '
            . 'FROM `forwext_post_reactions` r INNER JOIN `forwext_reaction_types` rt ON rt.`reaction_key` = r.`reaction_key` '
            . 'WHERE r.`post_id` = :post_id AND rt.`enabled` = 1 '
            . 'GROUP BY r.`reaction_key` ORDER BY rt.`display_order`, r.`reaction_key`',
            ['post_id' => $postId->value()],
        ));
        $counts = []; $total = 0; $score = 0;
        foreach ($rows as $row) {
            $count = (int) $row['reaction_count'];
            $counts[(string) $row['reaction_key']] = $count;
            $total += $count;
            $score += (int) $row['reaction_score'];
        }
        return new ReactionSummary($total, $score, $counts);
    }

    public function saveBookmark(EntityId $actorId, EntityId $postId, ?string $note): void
    {
        UserId::assert($actorId); PostId::assert($postId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_post_bookmarks` (`user_id`, `post_id`, `note`, `created_at_utc`, `updated_at_utc`) '
            . 'VALUES (:user_id, :post_id, :note, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `note` = VALUES(`note`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            ['user_id' => $actorId->value(), 'post_id' => $postId->value(), 'note' => $note],
        ));
    }

    public function removeBookmark(EntityId $actorId, EntityId $postId): void
    {
        UserId::assert($actorId); PostId::assert($postId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_post_bookmarks` WHERE `user_id` = :user_id AND `post_id` = :post_id',
            ['user_id' => $actorId->value(), 'post_id' => $postId->value()],
        ));
    }

    public function bookmarks(EntityId $actorId, int $limit = 50, int $offset = 0): array
    {
        UserId::assert($actorId);
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1_000_000) throw new SocialInteractionException('Bookmark pagination is invalid.');
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `post_id`, `note` FROM `forwext_post_bookmarks` WHERE `user_id` = :user_id '
            . 'ORDER BY `updated_at_utc` DESC, `post_id` LIMIT ' . $limit . ' OFFSET ' . $offset,
            ['user_id' => $actorId->value()],
        ));
        return array_map(
            static fn (array $row): BookmarkEntry => new BookmarkEntry(
                PostId::fromStored((string) $row['post_id']),
                isset($row['note']) && is_string($row['note']) ? $row['note'] : null,
            ),
            $rows,
        );
    }

    public function follow(EntityId $actorId, EntityId $targetId): void
    {
        $this->assertUserPair($actorId, $targetId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($actorId, $targetId): void {
            $this->lockActor($database, $actorId);
            $ignored = $database->fetchOne(new CompiledQuery(
                'SELECT `ignored_user_id` FROM `forwext_user_ignores` '
                . 'WHERE `user_id` = :user_id AND `ignored_user_id` = :target_id LIMIT 1',
                ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
            ));
            if ($ignored !== null) throw new SocialInteractionException('Ignored users cannot be followed.');
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_follows` (`follower_user_id`, `followed_user_id`, `created_at_utc`) '
                . 'VALUES (:user_id, :target_id, UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `created_at_utc` = `created_at_utc`',
                ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
            ));
        });
    }

    public function unfollow(EntityId $actorId, EntityId $targetId): void
    {
        $this->assertUserPair($actorId, $targetId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_user_follows` WHERE `follower_user_id` = :user_id AND `followed_user_id` = :target_id',
            ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
        ));
    }

    public function ignore(EntityId $actorId, EntityId $targetId): void
    {
        $this->assertUserPair($actorId, $targetId);
        $this->database->transaction(function (TransactionalQueryExecutor $database) use ($actorId, $targetId): void {
            $this->lockActor($database, $actorId);
            $database->execute(new CompiledQuery(
                'DELETE FROM `forwext_user_follows` WHERE `follower_user_id` = :user_id AND `followed_user_id` = :target_id',
                ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
            ));
            $database->execute(new CompiledQuery(
                'INSERT INTO `forwext_user_ignores` (`user_id`, `ignored_user_id`, `created_at_utc`) '
                . 'VALUES (:user_id, :target_id, UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `created_at_utc` = `created_at_utc`',
                ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
            ));
        });
    }

    public function unignore(EntityId $actorId, EntityId $targetId): void
    {
        $this->assertUserPair($actorId, $targetId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_user_ignores` WHERE `user_id` = :user_id AND `ignored_user_id` = :target_id',
            ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
        ));
    }

    public function isFollowing(EntityId $actorId, EntityId $targetId): bool
    {
        $this->assertUserPair($actorId, $targetId);
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_user_follows` WHERE `follower_user_id` = :user_id AND `followed_user_id` = :target_id',
            ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
        )) > 0;
    }

    public function isIgnoring(EntityId $actorId, EntityId $targetId): bool
    {
        $this->assertUserPair($actorId, $targetId);
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_user_ignores` WHERE `user_id` = :user_id AND `ignored_user_id` = :target_id',
            ['user_id' => $actorId->value(), 'target_id' => $targetId->value()],
        )) > 0;
    }

    public function ignoredUserIds(EntityId $actorId): array
    {
        UserId::assert($actorId);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `ignored_user_id` FROM `forwext_user_ignores` WHERE `user_id` = :user_id ORDER BY `ignored_user_id`',
            ['user_id' => $actorId->value()],
        ));
        return array_map(static fn (array $row): EntityId => UserId::fromStored((string) $row['ignored_user_id']), $rows);
    }

    private function assertUserPair(EntityId $actorId, EntityId $targetId): void
    {
        UserId::assert($actorId); UserId::assert($targetId);
        if ($actorId->value() === $targetId->value()) throw new SocialInteractionException('Self follow or ignore relationships are not allowed.');
    }

    private function lockActor(TransactionalQueryExecutor $database, EntityId $actorId): void
    {
        $row = $database->fetchOne(new CompiledQuery(
            'SELECT `user_id` FROM `forwext_users` WHERE `user_id` = :user_id FOR UPDATE',
            ['user_id' => $actorId->value()],
            true,
        ));
        if ($row === null) throw new SocialInteractionException('Actor user is unavailable.');
    }
}
