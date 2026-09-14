<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Database;

use Forwext\Core\Database\DatabaseException;
use Forwext\Core\Database\Query\DeleteQueryBuilder;
use Forwext\Core\Database\Query\InsertQueryBuilder;
use Forwext\Core\Database\Query\OrderDirection;
use Forwext\Core\Database\Query\SelectQueryBuilder;
use Forwext\Core\Database\Query\UpdateQueryBuilder;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    public function testSelectCompilesBoundParametersOrderingPaginationAndRowLock(): void
    {
        $query = (new SelectQueryBuilder('threads', ['t.id', 't.title'], 't'))
            ->whereEquals('t.forum_id', 7)
            ->whereNull('t.deleted_at')
            ->orderBy('t.created_at', OrderDirection::Desc)
            ->limit(20)
            ->offset(40)
            ->forUpdate()
            ->compile();

        self::assertSame(
            'SELECT `t`.`id`, `t`.`title` FROM `threads` AS `t` WHERE `t`.`forum_id` = :w1 AND `t`.`deleted_at` IS NULL ORDER BY `t`.`created_at` DESC LIMIT 20 OFFSET 40 FOR UPDATE',
            $query->sql,
        );
        self::assertSame(['w1' => 7], $query->parameters);
        self::assertTrue($query->requiresTransaction);
    }

    public function testInsertUpdateAndDeleteUseBoundValues(): void
    {
        $insert = (new InsertQueryBuilder('users'))
            ->values(['username' => 'benjamin17', 'active' => true])
            ->compile();
        self::assertSame(
            'INSERT INTO `users` (`username`, `active`) VALUES (:i1, :i2)',
            $insert->sql,
        );
        self::assertSame(['i1' => 'benjamin17', 'i2' => true], $insert->parameters);

        $update = (new UpdateQueryBuilder('users'))
            ->set('username', 'new-name')
            ->whereEquals('id', 10)
            ->compile();
        self::assertSame('UPDATE `users` SET `username` = :s1 WHERE `id` = :w1', $update->sql);
        self::assertSame(['s1' => 'new-name', 'w1' => 10], $update->parameters);

        $delete = (new DeleteQueryBuilder('users'))->whereEquals('id', 10)->compile();
        self::assertSame('DELETE FROM `users` WHERE `id` = :w1', $delete->sql);
        self::assertSame(['w1' => 10], $delete->parameters);
    }

    public function testUnsafeIdentifierIsRejectedInsteadOfQuotedAsRawSql(): void
    {
        $this->expectException(DatabaseException::class);
        new SelectQueryBuilder('users; DROP TABLE users', ['id']);
    }

    public function testUpdateWithoutWhereFailsClosed(): void
    {
        $this->expectException(DatabaseException::class);
        (new UpdateQueryBuilder('users'))->set('active', false)->compile();
    }

    public function testDeleteWithoutWhereFailsClosed(): void
    {
        $this->expectException(DatabaseException::class);
        (new DeleteQueryBuilder('users'))->compile();
    }

    public function testEmptyInListHasDeterministicFalsePredicate(): void
    {
        $query = (new SelectQueryBuilder('users', ['id']))
            ->whereIn('id', [])
            ->compile();

        self::assertSame('SELECT `id` FROM `users` WHERE 0 = 1', $query->sql);
        self::assertSame([], $query->parameters);
    }
}
