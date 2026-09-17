<?php

declare(strict_types=1);

namespace Forwext\Core\Profile\Activity;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Social\Interaction\ReactionSummary;
use UnexpectedValueException;

final readonly class DatabaseProfileActivityRepository implements ProfileActivityRepository
{
    public function __construct(private TransactionalQueryExecutor $database) {}

    public function settings(EntityId $profileOwnerUserId): ProfileActivitySettings
    {
        UserId::assert($profileOwnerUserId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `view_scope`, `post_scope` FROM `forwext_profile_activity_settings` WHERE `user_id` = :user_id LIMIT 1',
            ['user_id' => $profileOwnerUserId->value()],
        ));
        return $row === null
            ? new ProfileActivitySettings()
            : new ProfileActivitySettings(
                ProfileActivityScope::from((string) $row['view_scope']),
                ProfileActivityScope::from((string) $row['post_scope']),
            );
    }

    public function saveSettings(EntityId $profileOwnerUserId, ProfileActivitySettings $settings): void
    {
        UserId::assert($profileOwnerUserId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_profile_activity_settings` (`user_id`, `view_scope`, `post_scope`, `updated_at_utc`) '
            . 'VALUES (:user_id, :view_scope, :post_scope, UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `view_scope` = VALUES(`view_scope`), `post_scope` = VALUES(`post_scope`), '
            . '`updated_at_utc` = VALUES(`updated_at_utc`)',
            [
                'user_id' => $profileOwnerUserId->value(),
                'view_scope' => $settings->viewScope->value,
                'post_scope' => $settings->postScope->value,
            ],
        ));
    }

    public function createPost(EntityId $profileOwnerUserId, EntityId $authorUserId, ProfileActivityBody $body, DateTimeImmutable $now): ProfilePost
    {
        UserId::assert($profileOwnerUserId); UserId::assert($authorUserId);
        $id = ProfilePostId::generate();
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_profile_posts` '
            . '(`profile_post_id`, `profile_owner_user_id`, `author_user_id`, `body_source`, `moderation_state`, '
            . '`deleted_at_utc`, `created_at_utc`, `updated_at_utc`) '
            . "VALUES (:id, :owner_id, :author_id, :body, 'visible', NULL, :created_at, :updated_at)",
            [
                'id' => $id->value(), 'owner_id' => $profileOwnerUserId->value(), 'author_id' => $authorUserId->value(),
                'body' => $body->source(), 'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ],
        ));
        return new ProfilePost($id, $profileOwnerUserId, $authorUserId, $body, ProfileActivityModerationState::Visible, null, $now, $now);
    }

    public function findPost(EntityId $profilePostId): ?ProfilePost
    {
        ProfilePostId::assert($profilePostId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `profile_post_id`, `profile_owner_user_id`, `author_user_id`, `body_source`, `moderation_state`, '
            . '`deleted_at_utc`, `created_at_utc`, `updated_at_utc` FROM `forwext_profile_posts` '
            . 'WHERE `profile_post_id` = :id LIMIT 1',
            ['id' => $profilePostId->value()],
        ));
        return $row === null ? null : $this->hydratePost($row);
    }

    public function posts(EntityId $profileOwnerUserId, int $limit = 50, int $offset = 0): array
    {
        UserId::assert($profileOwnerUserId); $this->assertPage($limit, $offset, 100);
        return array_map(
            $this->hydratePost(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT `profile_post_id`, `profile_owner_user_id`, `author_user_id`, `body_source`, `moderation_state`, '
                . '`deleted_at_utc`, `created_at_utc`, `updated_at_utc` FROM `forwext_profile_posts` '
                . "WHERE `profile_owner_user_id` = :owner_id AND `moderation_state` = 'visible' AND `deleted_at_utc` IS NULL "
                . 'ORDER BY `created_at_utc` DESC, `profile_post_id` DESC LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['owner_id' => $profileOwnerUserId->value()],
            )),
        );
    }

    public function deletePost(EntityId $profilePostId, DateTimeImmutable $now): void
    {
        ProfilePostId::assert($profilePostId);
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_profile_posts` SET `deleted_at_utc` = :deleted_at, `updated_at_utc` = :updated_at '
            . 'WHERE `profile_post_id` = :id AND `deleted_at_utc` IS NULL',
            ['deleted_at' => $this->format($now), 'updated_at' => $this->format($now), 'id' => $profilePostId->value()],
        ));
    }

    public function createComment(EntityId $profilePostId, EntityId $authorUserId, ProfileActivityBody $body, DateTimeImmutable $now): ProfileComment
    {
        ProfilePostId::assert($profilePostId); UserId::assert($authorUserId);
        $id = ProfileCommentId::generate();
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_profile_comments` '
            . '(`comment_id`, `profile_post_id`, `author_user_id`, `body_source`, `moderation_state`, `deleted_at_utc`, '
            . '`created_at_utc`, `updated_at_utc`) '
            . "VALUES (:id, :post_id, :author_id, :body, 'visible', NULL, :created_at, :updated_at)",
            [
                'id' => $id->value(), 'post_id' => $profilePostId->value(), 'author_id' => $authorUserId->value(),
                'body' => $body->source(), 'created_at' => $this->format($now), 'updated_at' => $this->format($now),
            ],
        ));
        return new ProfileComment($id, $profilePostId, $authorUserId, $body, ProfileActivityModerationState::Visible, null, $now, $now);
    }

    public function findComment(EntityId $commentId): ?ProfileComment
    {
        ProfileCommentId::assert($commentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `comment_id`, `profile_post_id`, `author_user_id`, `body_source`, `moderation_state`, '
            . '`deleted_at_utc`, `created_at_utc`, `updated_at_utc` FROM `forwext_profile_comments` WHERE `comment_id` = :id LIMIT 1',
            ['id' => $commentId->value()],
        ));
        return $row === null ? null : $this->hydrateComment($row);
    }

    public function comments(EntityId $profilePostId, int $limit = 100, int $offset = 0): array
    {
        ProfilePostId::assert($profilePostId); $this->assertPage($limit, $offset, 200);
        return array_map(
            $this->hydrateComment(...),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT `comment_id`, `profile_post_id`, `author_user_id`, `body_source`, `moderation_state`, '
                . '`deleted_at_utc`, `created_at_utc`, `updated_at_utc` FROM `forwext_profile_comments` '
                . "WHERE `profile_post_id` = :post_id AND `moderation_state` = 'visible' AND `deleted_at_utc` IS NULL "
                . 'ORDER BY `created_at_utc`, `comment_id` LIMIT ' . $limit . ' OFFSET ' . $offset,
                ['post_id' => $profilePostId->value()],
            )),
        );
    }

    public function deleteComment(EntityId $commentId, DateTimeImmutable $now): void
    {
        ProfileCommentId::assert($commentId);
        $this->database->execute(new CompiledQuery(
            'UPDATE `forwext_profile_comments` SET `deleted_at_utc` = :deleted_at, `updated_at_utc` = :updated_at '
            . 'WHERE `comment_id` = :id AND `deleted_at_utc` IS NULL',
            ['deleted_at' => $this->format($now), 'updated_at' => $this->format($now), 'id' => $commentId->value()],
        ));
    }

    public function setReaction(EntityId $actorId, EntityId $profilePostId, string $reactionKey): void
    {
        UserId::assert($actorId); ProfilePostId::assert($profilePostId);
        $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_profile_post_reactions` '
            . '(`profile_post_id`, `user_id`, `reaction_key`, `created_at_utc`, `updated_at_utc`) '
            . 'VALUES (:post_id, :user_id, :reaction_key, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE `reaction_key` = VALUES(`reaction_key`), `updated_at_utc` = VALUES(`updated_at_utc`)',
            ['post_id' => $profilePostId->value(), 'user_id' => $actorId->value(), 'reaction_key' => $reactionKey],
        ));
    }

    public function removeReaction(EntityId $actorId, EntityId $profilePostId): void
    {
        UserId::assert($actorId); ProfilePostId::assert($profilePostId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM `forwext_profile_post_reactions` WHERE `profile_post_id` = :post_id AND `user_id` = :user_id',
            ['post_id' => $profilePostId->value(), 'user_id' => $actorId->value()],
        ));
    }

    public function reactionSummary(EntityId $profilePostId): ReactionSummary
    {
        ProfilePostId::assert($profilePostId);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT r.`reaction_key`, COUNT(*) AS `reaction_count`, SUM(rt.`score`) AS `reaction_score` '
            . 'FROM `forwext_profile_post_reactions` r INNER JOIN `forwext_reaction_types` rt ON rt.`reaction_key` = r.`reaction_key` '
            . 'WHERE r.`profile_post_id` = :post_id AND rt.`enabled` = 1 '
            . 'GROUP BY r.`reaction_key` ORDER BY rt.`display_order`, r.`reaction_key`',
            ['post_id' => $profilePostId->value()],
        ));
        $counts = []; $total = 0; $score = 0;
        foreach ($rows as $row) {
            $count = (int) $row['reaction_count'];
            $counts[(string) $row['reaction_key']] = $count;
            $total += $count; $score += (int) $row['reaction_score'];
        }
        return new ReactionSummary($total, $score, $counts);
    }

    /** @param array<string,mixed> $row */
    private function hydratePost(array $row): ProfilePost
    {
        foreach (['profile_post_id','profile_owner_user_id','body_source','moderation_state','created_at_utc','updated_at_utc'] as $key) {
            if (!array_key_exists($key, $row)) throw new UnexpectedValueException('Profile post row is incomplete.');
        }
        return new ProfilePost(
            ProfilePostId::fromStored((string) $row['profile_post_id']),
            UserId::fromStored((string) $row['profile_owner_user_id']),
            isset($row['author_user_id']) && is_string($row['author_user_id']) ? UserId::fromStored($row['author_user_id']) : null,
            ProfileActivityBody::fromString((string) $row['body_source']),
            ProfileActivityModerationState::from((string) $row['moderation_state']),
            $this->date($row['deleted_at_utc'] ?? null),
            $this->requiredDate($row['created_at_utc']),
            $this->requiredDate($row['updated_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateComment(array $row): ProfileComment
    {
        foreach (['comment_id','profile_post_id','body_source','moderation_state','created_at_utc','updated_at_utc'] as $key) {
            if (!array_key_exists($key, $row)) throw new UnexpectedValueException('Profile comment row is incomplete.');
        }
        return new ProfileComment(
            ProfileCommentId::fromStored((string) $row['comment_id']),
            ProfilePostId::fromStored((string) $row['profile_post_id']),
            isset($row['author_user_id']) && is_string($row['author_user_id']) ? UserId::fromStored($row['author_user_id']) : null,
            ProfileActivityBody::fromString((string) $row['body_source']),
            ProfileActivityModerationState::from((string) $row['moderation_state']),
            $this->date($row['deleted_at_utc'] ?? null),
            $this->requiredDate($row['created_at_utc']),
            $this->requiredDate($row['updated_at_utc']),
        );
    }

    private function assertPage(int $limit, int $offset, int $max): void
    {
        if ($limit < 1 || $limit > $max || $offset < 0 || $offset > 1_000_000) throw new ProfileActivityException('Profile activity pagination is invalid.');
    }

    private function format(DateTimeImmutable $date): string { return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private function requiredDate(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') throw new UnexpectedValueException('Profile activity timestamp is invalid.');
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
    private function date(mixed $value): ?DateTimeImmutable { return $value === null ? null : $this->requiredDate($value); }
}
