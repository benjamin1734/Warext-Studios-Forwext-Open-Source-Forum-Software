<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabasePublicApiV1ReadRepository implements PublicApiV1ReadRepository
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function user(string $userId): ?array
    {
        self::assertId($userId);

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `user_id`,`username`,`created_at_utc`,`updated_at_utc` FROM `forwext_users` '
            . "WHERE `user_id`=:user_id AND `status`='active' LIMIT 1",
            ['user_id'=>$userId],
        ));

        return $row === null ? null : [
            'id'=>(string) $row['user_id'],
            'username'=>(string) $row['username'],
            'created_at'=>self::timestamp((string) $row['created_at_utc']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
        ];
    }

    public function forums(int $page, int $perPage): ApiV1Page
    {
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `node_id`,`parent_id`,`title`,`slug`,`description`,`sort_order` FROM `forwext_nodes` '
            . "WHERE `node_type`='forum' AND `visibility`='listed' "
            . 'ORDER BY `sort_order`,`title`,`node_id` LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
        ));

        return self::pageResult($rows, $page, $perPage, self::forumRow(...));
    }

    public function forum(string $forumId): ?array
    {
        self::assertId($forumId);

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `node_id`,`parent_id`,`title`,`slug`,`description`,`sort_order` FROM `forwext_nodes` '
            . "WHERE `node_id`=:forum_id AND `node_type`='forum' AND `visibility`='listed' LIMIT 1",
            ['forum_id'=>$forumId],
        ));

        return $row === null ? null : self::forumRow($row);
    }

    public function threads(string $forumId, int $page, int $perPage): ?ApiV1Page
    {
        self::assertId($forumId);
        if ($this->forum($forumId) === null) {
            return null;
        }
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT t.`thread_id`,t.`forum_node_id`,t.`author_user_id`,t.`type_key`,t.`title`,'
            . 't.`locked`,t.`sticky`,t.`featured`,t.`created_at_utc`,t.`updated_at_utc` '
            . 'FROM `forwext_threads` t INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . "WHERE t.`forum_node_id`=:forum_id AND t.`moderation_state`='visible' "
            . 'AND t.`deleted`=0 AND t.`archived`=0 AND t.`merged_into_thread_id` IS NULL '
            . "AND n.`node_type`='forum' AND n.`visibility`='listed' "
            . 'ORDER BY t.`sticky` DESC,t.`featured` DESC,t.`updated_at_utc` DESC,t.`thread_id` DESC '
            . 'LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            ['forum_id'=>$forumId],
        ));

        return self::pageResult($rows, $page, $perPage, self::threadRow(...));
    }

    public function thread(string $threadId): ?array
    {
        self::assertId($threadId);

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT t.`thread_id`,t.`forum_node_id`,t.`author_user_id`,t.`type_key`,t.`title`,'
            . 't.`locked`,t.`sticky`,t.`featured`,t.`created_at_utc`,t.`updated_at_utc` '
            . 'FROM `forwext_threads` t INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . "WHERE t.`thread_id`=:thread_id AND t.`moderation_state`='visible' "
            . 'AND t.`deleted`=0 AND t.`archived`=0 AND t.`merged_into_thread_id` IS NULL '
            . "AND n.`node_type`='forum' AND n.`visibility`='listed' LIMIT 1",
            ['thread_id'=>$threadId],
        ));

        return $row === null ? null : self::threadRow($row);
    }

    public function posts(string $threadId, int $page, int $perPage): ?ApiV1Page
    {
        self::assertId($threadId);
        if ($this->thread($threadId) === null) {
            return null;
        }
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT p.`post_id`,p.`thread_id`,p.`author_user_id`,p.`position`,p.`body_source`,'
            . 'p.`created_at_utc`,p.`updated_at_utc` FROM `forwext_posts` p '
            . 'INNER JOIN `forwext_threads` t ON t.`thread_id`=p.`thread_id` '
            . 'INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . "WHERE p.`thread_id`=:thread_id AND p.`moderation_state`='visible' AND p.`deleted`=0 "
            . "AND t.`moderation_state`='visible' AND t.`deleted`=0 AND t.`archived`=0 "
            . 'AND t.`merged_into_thread_id` IS NULL '
            . "AND n.`node_type`='forum' AND n.`visibility`='listed' "
            . 'ORDER BY p.`position` ASC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
            ['thread_id'=>$threadId],
        ));

        return self::pageResult($rows, $page, $perPage, self::postRow(...));
    }

    public function post(string $postId): ?array
    {
        self::assertId($postId);

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT p.`post_id`,p.`thread_id`,p.`author_user_id`,p.`position`,p.`body_source`,'
            . 'p.`created_at_utc`,p.`updated_at_utc` FROM `forwext_posts` p '
            . 'INNER JOIN `forwext_threads` t ON t.`thread_id`=p.`thread_id` '
            . 'INNER JOIN `forwext_nodes` n ON n.`node_id`=t.`forum_node_id` '
            . "WHERE p.`post_id`=:post_id AND p.`moderation_state`='visible' AND p.`deleted`=0 "
            . "AND t.`moderation_state`='visible' AND t.`deleted`=0 AND t.`archived`=0 "
            . 'AND t.`merged_into_thread_id` IS NULL '
            . "AND n.`node_type`='forum' AND n.`visibility`='listed' LIMIT 1",
            ['post_id'=>$postId],
        ));

        return $row === null ? null : self::postRow($row);
    }

    public function modules(int $page, int $perPage): ApiV1Page
    {
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `module_key`,`state` FROM `forwext_first_party_modules` '
            . "WHERE `state`='enabled' ORDER BY `module_key` LIMIT " . ($limit + 1) . ' OFFSET ' . $offset,
        ));

        return self::pageResult($rows, $page, $perPage, static fn (array $row): array => [
            'key'=>(string) $row['module_key'],
            'state'=>(string) $row['state'],
        ]);
    }

    public function marketplace(int $page, int $perPage): ApiV1Page
    {
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `listing_id`,`seller_user_id`,`category_id`,`slug`,`title`,`description`,'
            . '`price_minor`,`currency`,`state`,`created_at_utc`,`updated_at_utc` '
            . 'FROM `forwext_marketplace_listings` '
            . "WHERE `state` IN ('active','sold') "
            . 'ORDER BY `updated_at_utc` DESC,`listing_id` DESC LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
        ));

        return self::pageResult($rows, $page, $perPage, self::marketplaceRow(...));
    }

    public function marketplaceListing(string $listingId): ?array
    {
        self::assertId($listingId);

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT `listing_id`,`seller_user_id`,`category_id`,`slug`,`title`,`description`,'
            . '`price_minor`,`currency`,`state`,`created_at_utc`,`updated_at_utc` '
            . 'FROM `forwext_marketplace_listings` WHERE `listing_id`=:listing_id '
            . "AND `state` IN ('active','sold') LIMIT 1",
            ['listing_id'=>$listingId],
        ));

        return $row === null ? null : self::marketplaceRow($row);
    }

    public function supportCategories(int $page, int $perPage): ApiV1Page
    {
        [$limit, $offset] = self::page($page, $perPage);

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `category_key`,`label`,`description`,`default_priority`,`sort_order` '
            . 'FROM `forwext_support_categories` WHERE `active`=1 '
            . 'ORDER BY `sort_order`,`category_key` LIMIT ' . ($limit + 1) . ' OFFSET ' . $offset,
        ));

        return self::pageResult($rows, $page, $perPage, static fn (array $row): array => [
            'key'=>(string) $row['category_key'],
            'label'=>(string) $row['label'],
            'description'=>(string) $row['description'],
            'default_priority'=>(string) $row['default_priority'],
            'sort_order'=>(int) $row['sort_order'],
        ]);
    }

    /** @return array{0:int,1:int} */
    private static function page(int $page, int $perPage): array
    {
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('API v1 pagination is invalid.');
        }
        $offset = ($page - 1) * $perPage;
        if ($offset > 10000000) {
            throw new InvalidArgumentException('API v1 pagination offset is too large.');
        }

        return [$perPage, $offset];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):array<string,mixed> $mapper
     */
    private static function pageResult(array $rows, int $page, int $perPage, callable $mapper): ApiV1Page
    {
        $hasMore = count($rows) > $perPage;
        if ($hasMore) {
            array_pop($rows);
        }

        return new ApiV1Page(array_map($mapper, $rows), $page, $perPage, $hasMore);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function forumRow(array $row): array
    {
        return [
            'id'=>(string) $row['node_id'],
            'parent_id'=>isset($row['parent_id']) ? (string) $row['parent_id'] : null,
            'title'=>(string) $row['title'],
            'slug'=>(string) $row['slug'],
            'description'=>(string) $row['description'],
            'sort_order'=>(int) $row['sort_order'],
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function threadRow(array $row): array
    {
        return [
            'id'=>(string) $row['thread_id'],
            'forum_id'=>(string) $row['forum_node_id'],
            'author_user_id'=>isset($row['author_user_id']) ? (string) $row['author_user_id'] : null,
            'type'=>(string) $row['type_key'],
            'title'=>(string) $row['title'],
            'locked'=>(bool) $row['locked'],
            'sticky'=>(bool) $row['sticky'],
            'featured'=>(bool) $row['featured'],
            'created_at'=>self::timestamp((string) $row['created_at_utc']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function postRow(array $row): array
    {
        return [
            'id'=>(string) $row['post_id'],
            'thread_id'=>(string) $row['thread_id'],
            'author_user_id'=>isset($row['author_user_id']) ? (string) $row['author_user_id'] : null,
            'position'=>(int) $row['position'],
            'body_source'=>(string) $row['body_source'],
            'created_at'=>self::timestamp((string) $row['created_at_utc']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function marketplaceRow(array $row): array
    {
        return [
            'id'=>(string) $row['listing_id'],
            'seller_user_id'=>(string) $row['seller_user_id'],
            'category_id'=>(string) $row['category_id'],
            'slug'=>(string) $row['slug'],
            'title'=>(string) $row['title'],
            'description'=>(string) $row['description'],
            'price_minor'=>(int) $row['price_minor'],
            'currency'=>(string) $row['currency'],
            'state'=>(string) $row['state'],
            'created_at'=>self::timestamp((string) $row['created_at_utc']),
            'updated_at'=>self::timestamp((string) $row['updated_at_utc']),
        ];
    }

    private static function assertId(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new InvalidArgumentException('API v1 entity id is invalid.');
        }
    }

    private static function timestamp(string $stored): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $stored, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable) {
            throw new RuntimeException('API v1 read model encountered an invalid timestamp.');
        }

        return $date->format('Y-m-d\TH:i:s.u\Z');
    }
}
