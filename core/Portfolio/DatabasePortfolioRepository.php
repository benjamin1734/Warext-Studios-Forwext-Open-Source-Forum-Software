<?php

declare(strict_types=1);

namespace Forwext\Core\Portfolio;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabasePortfolioRepository implements PortfolioRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function categories(bool $activeOnly = true): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT category_key,label,description,sort_order,active FROM forwext_portfolio_categories'
            . ($activeOnly ? ' WHERE active=1' : '')
            . ' ORDER BY sort_order,category_key',
        ));
        return array_map(
            static fn (array $row): PortfolioCategory => new PortfolioCategory(
                (string) $row['category_key'],
                (string) $row['label'],
                (string) $row['description'],
                (int) $row['sort_order'],
                (bool) $row['active'],
            ),
            $rows,
        );
    }

    public function category(string $key): ?PortfolioCategory
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT category_key,label,description,sort_order,active FROM forwext_portfolio_categories '
            . 'WHERE category_key=:category_key LIMIT 1',
            ['category_key' => $key],
        ));
        return $row === null ? null : new PortfolioCategory(
            (string) $row['category_key'],
            (string) $row['label'],
            (string) $row['description'],
            (int) $row['sort_order'],
            (bool) $row['active'],
        );
    }

    public function saveCategory(PortfolioCategory $category): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_portfolio_categories '
            . '(category_key,label,description,sort_order,active,created_at_utc,updated_at_utc) '
            . 'VALUES (:key,:label,:description,:sort_order,:active,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),'
            . 'sort_order=VALUES(sort_order),active=VALUES(active),updated_at_utc=VALUES(updated_at_utc)',
            [
                'key' => $category->key,
                'label' => $category->label,
                'description' => $category->description,
                'sort_order' => $category->sortOrder,
                'active' => $category->active,
            ],
        ));
    }

    public function project(EntityId $projectId): ?PortfolioProject
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT project_id,owner_user_id,category_key,slug,title,summary,description,state,featured,'
            . 'created_at_utc,updated_at_utc FROM forwext_portfolio_projects WHERE project_id=:project_id LIMIT 1',
            ['project_id' => $projectId->value()],
        ));
        return $row === null ? null : $this->hydrateProject($row);
    }

    public function projects(
        ?EntityId $ownerUserId = null,
        bool $publishedOnly = true,
        bool $featuredOnly = false,
        int $limit = 100,
    ): array {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Portfolio listing limit is invalid.');
        }
        $where = [];
        $parameters = [];
        if ($ownerUserId !== null) {
            UserId::assert($ownerUserId);
            $where[] = 'owner_user_id=:owner_user_id';
            $parameters['owner_user_id'] = $ownerUserId->value();
        }
        if ($publishedOnly) {
            $where[] = "state='published'";
        }
        if ($featuredOnly) {
            $where[] = 'featured=1';
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT project_id,owner_user_id,category_key,slug,title,summary,description,state,featured,'
            . 'created_at_utc,updated_at_utc FROM forwext_portfolio_projects'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY featured DESC,updated_at_utc DESC,project_id DESC LIMIT ' . $limit,
            $parameters,
        ));
        return array_map($this->hydrateProject(...), $rows);
    }

    public function saveProject(PortfolioProject $project): void
    {
        $persist = function () use ($project): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_portfolio_projects '
                . '(project_id,owner_user_id,category_key,slug,title,summary,description,state,featured,created_at_utc,updated_at_utc) '
                . 'VALUES (:id,:owner,:category,:slug,:title,:summary,:description,:state,:featured,:created,:updated) '
                . 'ON DUPLICATE KEY UPDATE category_key=VALUES(category_key),slug=VALUES(slug),title=VALUES(title),'
                . 'summary=VALUES(summary),description=VALUES(description),state=VALUES(state),featured=VALUES(featured),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                [
                    'id' => $project->projectId->value(),
                    'owner' => $project->ownerUserId->value(),
                    'category' => $project->categoryKey,
                    'slug' => $project->slug,
                    'title' => $project->title,
                    'summary' => $project->summary,
                    'description' => $project->description,
                    'state' => $project->state->value,
                    'featured' => $project->featured ? 1 : 0,
                    'created' => self::format($project->createdAt),
                    'updated' => self::format($project->updatedAt),
                ],
            ));

            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_portfolio_project_tags WHERE project_id=:project_id',
                ['project_id' => $project->projectId->value()],
            ));
            foreach ($project->tags as $tag) {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_portfolio_project_tags (project_id,tag_key) VALUES (:project_id,:tag_key)',
                    ['project_id' => $project->projectId->value(), 'tag_key' => $tag],
                ));
            }


        };

        if ($this->database->inTransaction()) {
            $persist();
        } else {
            $this->database->transaction(static fn () => $persist());
        }
    }

    public function comments(EntityId $projectId, bool $visibleOnly = true, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Portfolio comment limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT comment_id,project_id,author_user_id,body,state,created_at_utc '
            . 'FROM forwext_portfolio_comments WHERE project_id=:project_id'
            . ($visibleOnly ? " AND state='visible'" : '')
            . ' ORDER BY created_at_utc,comment_id LIMIT ' . $limit,
            ['project_id' => $projectId->value()],
        ));
        return array_map(
            static fn (array $row): PortfolioComment => new PortfolioComment(
                EntityId::fromString((string) $row['comment_id']),
                EntityId::fromString((string) $row['project_id']),
                EntityId::fromString((string) $row['author_user_id']),
                (string) $row['body'],
                PortfolioCommentState::from((string) $row['state']),
                self::parse((string) $row['created_at_utc']),
            ),
            $rows,
        );
    }

    public function saveComment(PortfolioComment $comment): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_portfolio_comments '
            . '(comment_id,project_id,author_user_id,body,state,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:project_id,:author,:body,:state,:created,:created)',
            [
                'id' => $comment->commentId->value(),
                'project_id' => $comment->projectId->value(),
                'author' => $comment->authorUserId->value(),
                'body' => $comment->body,
                'state' => $comment->state->value,
                'created' => self::format($comment->createdAt),
            ],
        ));
    }

    public function setReaction(EntityId $actorUserId, EntityId $projectId, string $reactionKey): void
    {
        UserId::assert($actorUserId);
        $exists = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_reaction_types WHERE reaction_key=:reaction_key AND enabled=1',
            ['reaction_key' => $reactionKey],
        ));
        if ($exists !== 1) {
            throw new InvalidArgumentException('Portfolio reaction type is unavailable.');
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_portfolio_reactions '
            . '(project_id,user_id,reaction_key,created_at_utc,updated_at_utc) '
            . 'VALUES (:project_id,:user_id,:reaction_key,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE reaction_key=VALUES(reaction_key),updated_at_utc=VALUES(updated_at_utc)',
            ['project_id' => $projectId->value(), 'user_id' => $actorUserId->value(), 'reaction_key' => $reactionKey],
        ));
    }

    public function removeReaction(EntityId $actorUserId, EntityId $projectId): void
    {
        UserId::assert($actorUserId);
        $this->database->execute(new CompiledQuery(
            'DELETE FROM forwext_portfolio_reactions WHERE project_id=:project_id AND user_id=:user_id',
            ['project_id' => $projectId->value(), 'user_id' => $actorUserId->value()],
        ));
    }

    public function reactionSummary(EntityId $projectId): PortfolioReactionSummary
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT r.reaction_key,COUNT(*) AS reaction_count,SUM(rt.score) AS reaction_score '
            . 'FROM forwext_portfolio_reactions r INNER JOIN forwext_reaction_types rt ON rt.reaction_key=r.reaction_key '
            . 'WHERE r.project_id=:project_id AND rt.enabled=1 GROUP BY r.reaction_key '
            . 'ORDER BY rt.display_order,r.reaction_key',
            ['project_id' => $projectId->value()],
        ));
        $counts = [];
        $total = 0;
        $score = 0;
        foreach ($rows as $row) {
            $count = (int) $row['reaction_count'];
            $counts[(string) $row['reaction_key']] = $count;
            $total += $count;
            $score += (int) $row['reaction_score'];
        }
        return new PortfolioReactionSummary($total, $score, $counts);
    }

    public function recordHistory(
        EntityId $projectId,
        EntityId $actorUserId,
        string $action,
        ?string $fromState,
        ?string $toState,
    ): void {
        UserId::assert($actorUserId);
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $action) !== 1) {
            throw new InvalidArgumentException('Portfolio history action is invalid.');
        }
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_portfolio_history '
            . '(history_id,project_id,actor_user_id,action,from_state,to_state,created_at_utc) '
            . 'VALUES (:id,:project_id,:actor,:action,:from_state,:to_state,UTC_TIMESTAMP(6))',
            [
                'id' => bin2hex(random_bytes(16)),
                'project_id' => $projectId->value(),
                'actor' => $actorUserId->value(),
                'action' => $action,
                'from_state' => $fromState,
                'to_state' => $toState,
            ],
        ));
    }

    /** @param array<string,mixed> $row */
    private function hydrateProject(array $row): PortfolioProject
    {
        try {
            $state = PortfolioState::from((string) $row['state']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored portfolio state is invalid.', previous: $exception);
        }

        $tags = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT tag_key FROM forwext_portfolio_project_tags WHERE project_id=:project_id ORDER BY tag_key',
            ['project_id' => (string) $row['project_id']],
        )) as $tagRow) {
            $tags[] = (string) $tagRow['tag_key'];
        }

        $media = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT media_id,path,alt_text,sort_order FROM forwext_portfolio_media '
            . 'WHERE project_id=:project_id ORDER BY sort_order,media_id',
            ['project_id' => (string) $row['project_id']],
        )) as $mediaRow) {
            $media[] = new PortfolioMedia(
                (string) $mediaRow['path'],
                (string) $mediaRow['alt_text'],
                (int) $mediaRow['sort_order'],
                EntityId::fromString((string) $mediaRow['media_id']),
            );
        }

        return new PortfolioProject(
            EntityId::fromString((string) $row['project_id']),
            EntityId::fromString((string) $row['owner_user_id']),
            (string) $row['category_key'],
            (string) $row['slug'],
            (string) $row['title'],
            (string) $row['summary'],
            (string) $row['description'],
            $tags,
            $media,
            $state,
            (bool) $row['featured'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u', '!Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }
        throw new RuntimeException('Stored portfolio timestamp is invalid.');
    }
}
