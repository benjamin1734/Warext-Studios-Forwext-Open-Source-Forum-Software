<?php

declare(strict_types=1);

namespace Forwext\Tests\Security\Qualification;

use Forwext\Core\Database\DatabaseException;
use Forwext\Core\Database\Query\SqlIdentifier;
use PHPUnit\Framework\TestCase;

final class SqlInjectionBoundaryTest extends TestCase
{
    public function testUnsafeDynamicSqlIdentifiersAreRejected(): void
    {
        $payloads = [
            'users;DROP TABLE users',
            'users--',
            'users/*comment*/',
            'users`',
            'users.id OR 1=1',
            'users UNION SELECT password',
            'users id',
        ];

        foreach ($payloads as $payload) {
            try {
                SqlIdentifier::quote($payload);
                self::fail('Unsafe SQL identifier was accepted: ' . $payload);
            } catch (DatabaseException) {
                self::assertTrue(true);
            }
        }
    }

    public function testQualifiedAndSelectableIdentifiersRemainStrictlyQuoted(): void
    {
        self::assertSame('`users`.`id`', SqlIdentifier::quote('users.id'));
        self::assertSame('`users`.*', SqlIdentifier::selectable('users.*'));
    }
}
