<?php

declare(strict_types=1);

namespace Forwext\Core\Profile;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;

final readonly class ProfileDirectoryReader
{
    public function __construct(private QueryExecutor $database)
    {
    }

    /** @return list<array{user_id:string,username:string,created_at:string,has_avatar:bool}> */
    public function latestPublic(int $limit = 24): array
    {
        return $this->searchPublic('', 'newest', $limit, 0);
    }

    /** @return list<array{user_id:string,username:string,created_at:string,has_avatar:bool}> */
    public function searchPublic(
        string $query = '',
        string $sort = 'newest',
        int $limit = 24,
        int $offset = 0,
    ): array {
        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 100000) {
            throw new ProfileException('Member directory pagination is invalid.');
        }
        if (strlen($query) > 64 || !in_array($sort, ['newest', 'username'], true)) {
            throw new ProfileException('Member directory query is invalid.');
        }

        $where = "u.`status` = 'active' AND COALESCE(p.`profile_visibility`, 'public') = 'public'";
        $parameters = [];
        if ($query !== '') {
            $where .= ' AND u.`username` LIKE :username_query ESCAPE \'=\'';
            $parameters['username_query'] = '%' . self::escapeLike($query) . '%';
        }
        $order = $sort === 'username'
            ? 'u.`username_key` ASC, u.`user_id` ASC'
            : 'u.`created_at_utc` DESC, u.`user_id` DESC';

        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT u.`user_id`, u.`username`, u.`created_at_utc`, p.`avatar_path` '
            . 'FROM `forwext_users` u '
            . 'LEFT JOIN `forwext_user_profiles` p ON p.`user_id` = u.`user_id` '
            . 'WHERE ' . $where . ' ORDER BY ' . $order
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset,
            $parameters,
        ));

        return array_map(
            static fn (array $row): array => [
                'user_id' => (string) $row['user_id'],
                'username' => (string) $row['username'],
                'created_at' => (string) $row['created_at_utc'],
                'has_avatar' => is_string($row['avatar_path'] ?? null)
                    && $row['avatar_path'] !== '',
            ],
            $rows,
        );
    }

    public function countPublic(string $query = ''): int
    {
        if (strlen($query) > 64) {
            throw new ProfileException('Member directory query is invalid.');
        }
        $where = "u.`status` = 'active' AND COALESCE(p.`profile_visibility`, 'public') = 'public'";
        $parameters = [];
        if ($query !== '') {
            $where .= ' AND u.`username` LIKE :username_query ESCAPE \'=\'';
            $parameters['username_query'] = '%' . self::escapeLike($query) . '%';
        }

        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_users` u '
            . 'LEFT JOIN `forwext_user_profiles` p ON p.`user_id` = u.`user_id` '
            . 'WHERE ' . $where,
            $parameters,
        ));
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }
}
