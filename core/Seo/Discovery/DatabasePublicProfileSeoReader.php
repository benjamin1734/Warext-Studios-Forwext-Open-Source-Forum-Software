<?php

declare(strict_types=1);

namespace Forwext\Core\Seo\Discovery;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Profile\Url\ProfileSlug;
use InvalidArgumentException;
use RuntimeException;

final readonly class DatabasePublicProfileSeoReader
{
    public function __construct(private QueryExecutor $database)
    {
    }

    /** @return list<PublicProfileSeoRecord> */
    public function latest(int $limit): array
    {
        if ($limit < 1 || $limit > 50000) {
            throw new InvalidArgumentException('Public profile discovery limit must be 1..50000.');
        }

        return array_map(
            self::hydrate(...),
            $this->database->fetchAll(new CompiledQuery(
                $this->baseSelect()
                . ' ORDER BY `discovery_updated_at` DESC, `u`.`user_id` ASC LIMIT ' . $limit,
            )),
        );
    }

    public function findByUsername(string $username): ?PublicProfileSeoRecord
    {
        try {
            $key = Username::fromString(rawurldecode($username))->key();
        } catch (InvalidArgumentException) {
            return null;
        }

        $row = $this->database->fetchOne(new CompiledQuery(
            $this->baseSelect() . ' AND `u`.`username_key` = :username_key LIMIT 1',
            ['username_key' => $key],
        ));

        return $row === null ? null : self::hydrate($row);
    }

    public function findBySlug(string $slug): ?PublicProfileSeoRecord
    {
        try {
            $key = ProfileSlug::fromString(rawurldecode($slug))->value();
        } catch (\Throwable) {
            return null;
        }

        $row = $this->database->fetchOne(new CompiledQuery(
            $this->baseSelect() . ' AND `pu`.`slug_key` = :slug_key LIMIT 1',
            ['slug_key' => $key],
        ));

        return $row === null ? null : self::hydrate($row);
    }

    private function baseSelect(): string
    {
        return 'SELECT `u`.`username`, `pu`.`slug_key`, '
            . 'GREATEST(`u`.`updated_at_utc`, COALESCE(`p`.`updated_at_utc`, `u`.`updated_at_utc`), '
            . 'COALESCE(`pu`.`changed_at_utc`, `u`.`updated_at_utc`)) AS `discovery_updated_at` '
            . 'FROM `forwext_users` `u` '
            . 'LEFT JOIN `forwext_user_profiles` `p` ON `p`.`user_id` = `u`.`user_id` '
            . 'LEFT JOIN `forwext_user_profile_urls` `pu` ON `pu`.`user_id` = `u`.`user_id` '
            . "WHERE `u`.`status` = 'active' "
            . "AND COALESCE(`p`.`profile_visibility`, 'public') = 'public'";
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PublicProfileSeoRecord
    {
        if (!isset($row['username'], $row['discovery_updated_at'])) {
            throw new RuntimeException('Public profile SEO row is incomplete.');
        }

        $updatedAt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            (string) $row['discovery_updated_at'],
            new DateTimeZone('UTC'),
        );
        if (!$updatedAt instanceof DateTimeImmutable) {
            throw new RuntimeException('Public profile SEO timestamp is invalid.');
        }

        $slug = $row['slug_key'] ?? null;
        return new PublicProfileSeoRecord(
            (string) $row['username'],
            is_string($slug) && $slug !== '' ? $slug : null,
            $updatedAt,
        );
    }
}
