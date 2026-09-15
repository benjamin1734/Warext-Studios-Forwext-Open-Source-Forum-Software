<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile\Url;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseException;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\Url\BaselineProfileUrlPermissionResolver;
use Forwext\Core\Profile\Url\DatabaseProfileUrlStore;
use Forwext\Core\Profile\Url\ProfileSlug;
use Forwext\Core\Profile\Url\ProfileSlugPolicy;
use Forwext\Core\Profile\Url\ProfileUrlException;
use Forwext\Core\Profile\Url\ProfileUrlService;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProfileUrlSystemTest extends TestCase
{
    public function testSlugNormalizationAndReservedNamesAreCaseInsensitive(): void
    {
        $slug = ProfileSlug::fromString('  My-Profile17  ');
        self::assertSame('my-profile17', $slug->value());

        $policy = new ProfileSlugPolicy(['Admin', 'WAREXT-STUDIOS']);
        self::assertSame(['admin', 'warext-studios'], $policy->reserved());

        $this->expectException(ProfileUrlException::class);
        $policy->assertAllowed(ProfileSlug::fromString('ADMIN'));
    }

    public function testInvalidDoubleHyphenSlugIsRejected(): void
    {
        $this->expectException(ProfileUrlException::class);
        ProfileSlug::fromString('bad--slug');
    }

    public function testPermissionAndOwnershipAreEnforcedBeforeClaim(): void
    {
        $owner = UserId::generate();
        $other = UserId::generate();
        $database = new ProfileUrlMemoryDatabase([$owner]);
        $service = new ProfileUrlService(
            new DatabaseProfileUrlStore($database),
            new OwnerSafeProfileAccessPolicy(),
            new BaselineProfileUrlPermissionResolver(false),
            new ProfileSlugPolicy([]),
        );

        try {
            $service->assign($owner, $owner, 'owner-slug', $this->time('2026-09-15 12:00:00'));
            self::fail('Disabled profile URL permission must reject a claim.');
        } catch (ProfileUrlException) {
            self::assertSame([], $database->claims());
        }

        $service = new ProfileUrlService(
            new DatabaseProfileUrlStore($database),
            new OwnerSafeProfileAccessPolicy(),
            new BaselineProfileUrlPermissionResolver(true),
            new ProfileSlugPolicy([]),
        );

        $this->expectException(ProfileUrlException::class);
        $service->assign($owner, $other, 'owner-slug', $this->time('2026-09-15 12:00:00'));
    }

    public function testHistoricalSlugRedirectsAndCanNeverBeReusedByAnotherUser(): void
    {
        $owner = UserId::generate();
        $other = UserId::generate();
        $database = new ProfileUrlMemoryDatabase([$owner, $other]);
        $store = new DatabaseProfileUrlStore($database);

        $first = $store->claim(
            $owner,
            ProfileSlug::fromString('first-slug'),
            $this->time('2026-09-01 12:00:00'),
            86400,
            2592000,
            3,
        );
        self::assertSame(0, $first->changesInWindow);

        $second = $store->claim(
            $owner,
            ProfileSlug::fromString('second-slug'),
            $this->time('2026-09-02 12:00:00'),
            86400,
            2592000,
            3,
        );
        self::assertSame(1, $second->changesInWindow);

        $historical = $store->resolve(ProfileSlug::fromString('FIRST-SLUG'));
        self::assertNotNull($historical);
        self::assertFalse($historical->isCurrent);
        self::assertSame('second-slug', $historical->currentSlug->value());
        self::assertTrue($historical->userId->equals($owner));

        try {
            $store->claim(
                $other,
                ProfileSlug::fromString('first-slug'),
                $this->time('2026-09-10 12:00:00'),
                86400,
                2592000,
                3,
            );
            self::fail('Historical slugs must remain permanently reserved.');
        } catch (ProfileUrlException $exception) {
            self::assertStringContainsString('unavailable', $exception->getMessage());
        }
    }

    public function testSameSlugIsIdempotentEvenDuringCooldown(): void
    {
        $owner = UserId::generate();
        $store = new DatabaseProfileUrlStore(new ProfileUrlMemoryDatabase([$owner]));
        $first = $store->claim(
            $owner,
            ProfileSlug::fromString('stable-slug'),
            $this->time('2026-09-15 12:00:00'),
            86400,
            2592000,
            3,
        );
        $same = $store->claim(
            $owner,
            ProfileSlug::fromString('STABLE-SLUG'),
            $this->time('2026-09-15 12:01:00'),
            86400,
            2592000,
            3,
        );

        self::assertSame($first->slug->value(), $same->slug->value());
        self::assertSame($first->changedAt->format('c'), $same->changedAt->format('c'));
        self::assertSame(0, $same->changesInWindow);
    }

    public function testCooldownRejectsEarlyChange(): void
    {
        $owner = UserId::generate();
        $store = new DatabaseProfileUrlStore(new ProfileUrlMemoryDatabase([$owner]));
        $store->claim(
            $owner,
            ProfileSlug::fromString('first-slug'),
            $this->time('2026-09-15 12:00:00'),
            86400,
            2592000,
            3,
        );

        $this->expectException(ProfileUrlException::class);
        $this->expectExceptionMessage('cooldown');
        $store->claim(
            $owner,
            ProfileSlug::fromString('second-slug'),
            $this->time('2026-09-16 11:59:59'),
            86400,
            2592000,
            3,
        );
    }

    public function testChangeWindowLimitAndResetAreEnforced(): void
    {
        $owner = UserId::generate();
        $store = new DatabaseProfileUrlStore(new ProfileUrlMemoryDatabase([$owner]));
        $policy = [86400, 2592000, 3];

        $store->claim($owner, ProfileSlug::fromString('slug-one'), $this->time('2026-08-01 12:00:00'), ...$policy);
        $store->claim($owner, ProfileSlug::fromString('slug-two'), $this->time('2026-08-02 12:00:00'), ...$policy);
        $store->claim($owner, ProfileSlug::fromString('slug-three'), $this->time('2026-08-03 12:00:00'), ...$policy);
        $thirdChange = $store->claim(
            $owner,
            ProfileSlug::fromString('slug-four'),
            $this->time('2026-08-04 12:00:00'),
            ...$policy,
        );
        self::assertSame(3, $thirdChange->changesInWindow);

        try {
            $store->claim(
                $owner,
                ProfileSlug::fromString('slug-five'),
                $this->time('2026-08-05 12:00:00'),
                ...$policy,
            );
            self::fail('A fourth change inside the window must be rejected.');
        } catch (ProfileUrlException $exception) {
            self::assertStringContainsString('limit', $exception->getMessage());
        }

        $reset = $store->claim(
            $owner,
            ProfileSlug::fromString('slug-six'),
            $this->time('2026-09-01 12:00:00'),
            ...$policy,
        );
        self::assertSame(1, $reset->changesInWindow);
        self::assertSame('2026-09-01T12:00:00+00:00', $reset->windowStartedAt->format('c'));
    }

    public function testConcurrentDuplicateInsertIsMappedToUnavailableInsteadOfDatabaseFailure(): void
    {
        $owner = UserId::generate();
        $store = new DatabaseProfileUrlStore(new DuplicateRaceProfileUrlExecutor($owner));

        $this->expectException(ProfileUrlException::class);
        $this->expectExceptionMessage('unavailable');
        $store->claim(
            $owner,
            ProfileSlug::fromString('race-slug'),
            $this->time('2026-09-15 12:00:00'),
            86400,
            2592000,
            3,
        );
    }

    private function time(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}

final class ProfileUrlMemoryDatabase implements TransactionalQueryExecutor
{
    /** @var array<string, true> */
    private array $users = [];

    /** @var array<string, array{slug_key:string,changed_at_utc:string,window_started_at_utc:string,changes_in_window:int}> */
    private array $current = [];

    /** @var array<string, array{slug_key:string,user_id:?string,claimed_at_utc:string,retired_at_utc:?string}> */
    private array $claimRows = [];

    private bool $transaction = false;

    /** @param list<EntityId> $users */
    public function __construct(array $users)
    {
        foreach ($users as $user) {
            $this->users[$user->value()] = true;
        }
    }

    public function execute(CompiledQuery $query): int
    {
        $sql = $query->sql;
        $parameters = $query->parameters;

        if (str_starts_with($sql, 'INSERT INTO `forwext_profile_url_claims`')) {
            $slug = (string) $parameters['slug_key'];
            if (isset($this->claimRows[$slug])) {
                throw new RuntimeException('Test database duplicate claim.');
            }
            $this->claimRows[$slug] = [
                'slug_key' => $slug,
                'user_id' => (string) $parameters['user_id'],
                'claimed_at_utc' => (string) $parameters['claimed_at'],
                'retired_at_utc' => null,
            ];
            return 1;
        }

        if (str_starts_with($sql, 'UPDATE `forwext_profile_url_claims`')) {
            $slug = (string) $parameters['old_slug'];
            if (!isset($this->claimRows[$slug])) {
                return 0;
            }
            $this->claimRows[$slug]['retired_at_utc'] = (string) $parameters['retired_at'];
            return 1;
        }

        if (str_starts_with($sql, 'INSERT INTO `forwext_user_profile_urls`')) {
            $userId = (string) $parameters['user_id'];
            $this->current[$userId] = [
                'slug_key' => (string) $parameters['slug_key'],
                'changed_at_utc' => (string) $parameters['changed_at'],
                'window_started_at_utc' => (string) $parameters['window_started_at'],
                'changes_in_window' => (int) $parameters['changes'],
            ];
            return 1;
        }

        throw new RuntimeException('Unexpected test execute query: ' . $sql);
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        $sql = $query->sql;
        $parameters = $query->parameters;

        if (str_contains($sql, 'FROM `forwext_users`')) {
            $userId = (string) $parameters['user_id'];
            return isset($this->users[$userId]) ? ['id' => $userId] : null;
        }

        if (str_contains($sql, 'FROM `forwext_profile_url_claims` c')) {
            $slug = (string) $parameters['slug_key'];
            $claim = $this->claimRows[$slug] ?? null;
            if ($claim === null || $claim['user_id'] === null) {
                return null;
            }
            $current = $this->current[$claim['user_id']] ?? null;
            return [
                'user_id' => $claim['user_id'],
                'retired_at_utc' => $claim['retired_at_utc'],
                'current_slug' => $current['slug_key'] ?? null,
            ];
        }

        if (str_contains($sql, 'FROM `forwext_profile_url_claims` WHERE')) {
            $slug = (string) $parameters['slug_key'];
            return isset($this->claimRows[$slug]) ? ['slug_key' => $slug] : null;
        }

        if (str_contains($sql, 'FROM `forwext_user_profile_urls`')) {
            $userId = (string) $parameters['user_id'];
            return $this->current[$userId] ?? null;
        }

        throw new RuntimeException('Unexpected test fetchOne query: ' . $sql);
    }

    public function fetchAll(CompiledQuery $query): array
    {
        unset($query);
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        unset($query);
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transaction = true;
        try {
            return $callback($this);
        } finally {
            $this->transaction = false;
        }
    }

    /** @return array<string, array{slug_key:string,user_id:?string,claimed_at_utc:string,retired_at_utc:?string}> */
    public function claims(): array
    {
        return $this->claimRows;
    }
}

final class DuplicateRaceProfileUrlExecutor implements TransactionalQueryExecutor
{
    private bool $transaction = false;

    public function __construct(private readonly EntityId $owner)
    {
    }

    public function execute(CompiledQuery $query): int
    {
        if (str_starts_with($query->sql, 'INSERT INTO `forwext_profile_url_claims`')) {
            $pdo = new PDOException('Duplicate entry', 23000);
            $pdo->errorInfo = ['23000', 1062, 'Duplicate entry'];
            throw new DatabaseException('Database query execution failed.', previous: $pdo);
        }

        throw new RuntimeException('Unexpected race test execute query.');
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        if (str_contains($query->sql, 'FROM `forwext_users`')) {
            return ['id' => $this->owner->value()];
        }
        if (str_contains($query->sql, 'FROM `forwext_user_profile_urls`')) {
            return null;
        }
        if (str_contains($query->sql, 'FROM `forwext_profile_url_claims` WHERE')) {
            return null;
        }

        throw new RuntimeException('Unexpected race test fetchOne query.');
    }

    public function fetchAll(CompiledQuery $query): array
    {
        unset($query);
        return [];
    }

    public function fetchValue(CompiledQuery $query): mixed
    {
        unset($query);
        return null;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function transaction(Closure $callback): mixed
    {
        $this->transaction = true;
        try {
            return $callback($this);
        } finally {
            $this->transaction = false;
        }
    }
}
