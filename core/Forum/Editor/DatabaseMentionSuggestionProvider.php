<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;

final readonly class DatabaseMentionSuggestionProvider implements MentionSuggestionProvider
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function suggest(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if ($limit < 1 || $limit > 10) {
            throw new InvalidArgumentException('Mention suggestion limit must be between 1 and 10.');
        }
        if ($query === '' || preg_match('//u', $query) !== 1) {
            return [];
        }
        $characters = preg_match_all('/./us', $query, $unused);
        if ($characters === false || $characters > 32
            || preg_match('/\A[\p{L}\p{N}][\p{L}\p{N}._-]*\z/u', $query) !== 1
        ) {
            return [];
        }

        $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $query);
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT `user_id`, `username` FROM `forwext_users` '
            . "WHERE `status` = 'active' AND `username` LIKE :prefix ESCAPE '=' "
            . 'ORDER BY `username_key` ASC, `user_id` ASC LIMIT ' . $limit,
            ['prefix' => $escaped . '%'],
        ));

        $suggestions = [];
        foreach ($rows as $row) {
            $suggestions[] = new MentionSuggestion(
                UserId::fromStored((string) $row['user_id']),
                (string) $row['username'],
            );
        }
        return $suggestions;
    }
}
