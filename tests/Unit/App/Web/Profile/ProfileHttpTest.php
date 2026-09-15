<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileMediaHandler;
use Forwext\App\Web\Profile\ProfileViewHandler;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileMediaService;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileStore;
use Forwext\Core\Profile\ProfileTab;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\SocialLink;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Storage\StoredObject;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProfileHttpTest extends TestCase
{
    public function testPublicProfileEscapesAboutAndRendersOnlySupportedVisibleTabs(): void
    {
        $user = $this->user('benjamin17');
        $profile = $this->profile(
            $user->id(),
            ProfileVisibility::Public,
            '<b>unsafe</b>',
            [new SocialLink('github', 'https://github.com/example?a=1&b=2')],
            [
                new ProfileTab('overview', true, ProfileVisibility::Public, 0),
                new ProfileTab('activity', true, ProfileVisibility::Public, 10),
                new ProfileTab('about', true, ProfileVisibility::Public, 20),
            ],
        );
        $policy = new OwnerSafeProfileAccessPolicy();
        $handler = new ProfileViewHandler(
            new MemoryUserRepository($user),
            new ProfileService(new HttpMemoryProfileStore($profile), $policy),
            $policy,
            new FixedViewerResolver(null),
            new BasePath('/forum'),
        );

        $response = $handler->handle($this->profileRequest('benjamin17'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('&lt;b&gt;unsafe&lt;/b&gt;', $response->body());
        self::assertStringNotContainsString('<b>unsafe</b>', $response->body());
        self::assertStringContainsString('https://github.com/example?a=1&amp;b=2', $response->body());
        self::assertStringContainsString('/forum/members/benjamin17', $response->body());
        self::assertStringNotContainsString('href="#activity"', $response->body());
    }

    public function testPrivateProfileReturnsNotFoundForNonOwner(): void
    {
        $user = $this->user('privateuser');
        $profile = $this->profile($user->id(), ProfileVisibility::Private);
        $policy = new OwnerSafeProfileAccessPolicy();
        $handler = new ProfileViewHandler(
            new MemoryUserRepository($user),
            new ProfileService(new HttpMemoryProfileStore($profile), $policy),
            $policy,
            new FixedViewerResolver(UserId::generate()),
            new BasePath(),
        );

        $response = $handler->handle($this->profileRequest('privateuser'));

        self::assertSame(404, $response->status());
        self::assertSame('Not Found', $response->body());
    }

    public function testMembersOnlyProfileIsVisibleToAuthenticatedMember(): void
    {
        $user = $this->user('membersuser');
        $profile = $this->profile($user->id(), ProfileVisibility::Members, 'members only');
        $policy = new OwnerSafeProfileAccessPolicy();
        $handler = new ProfileViewHandler(
            new MemoryUserRepository($user),
            new ProfileService(new HttpMemoryProfileStore($profile), $policy),
            $policy,
            new FixedViewerResolver(UserId::generate()),
            new BasePath(),
        );

        $response = $handler->handle($this->profileRequest('membersuser'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('members only', $response->body());
    }

    public function testPrivateMediaIsReturnedOnlyToOwner(): void
    {
        $user = $this->user('mediaowner');
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);
        $path = 'profiles/' . $user->id()->value() . '/avatar/' . str_repeat('a', 64) . '.png';
        $profile = new UserProfile(
            $user->id(),
            '',
            $path,
            null,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Private,
            [],
            ProfileService::defaultTabs(),
            $this->now(),
        );
        $store = new HttpMemoryProfileStore($profile);
        $storage = new HttpMemoryStorageDriver();
        $storage->put(StoragePath::fromString($path), $png, StorageVisibility::Private, 'image/png');
        $service = new ProfileMediaService($store, $storage);
        $repository = new MemoryUserRepository($user);

        $denied = new ProfileMediaHandler(
            $repository,
            $service,
            new FixedViewerResolver(UserId::generate()),
            ProfileMediaKind::Avatar,
        );
        self::assertSame(404, $denied->handle($this->profileRequest('mediaowner'))->status());

        $allowed = new ProfileMediaHandler(
            $repository,
            $service,
            new FixedViewerResolver($user->id()),
            ProfileMediaKind::Avatar,
        );
        $response = $allowed->handle($this->profileRequest('mediaowner'));
        self::assertSame(200, $response->status());
        self::assertSame('image/png', $response->headers()->first('content-type'));
        self::assertSame('nosniff', $response->headers()->first('x-content-type-options'));
        self::assertSame($png, $response->body());
    }

    private function profileRequest(string $username): Request
    {
        return (new Request(HttpMethod::Get, '/members/' . rawurlencode($username)))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, ['username' => $username]);
    }

    private function user(string $username): User
    {
        return User::create(
            UserId::generate(),
            Username::fromString($username),
            EmailAddress::fromString($username . '@example.test'),
            UserStatus::Active,
            UserLocale::fromString('tr-TR'),
            UserTimezone::fromString('UTC'),
            $this->now(),
        );
    }

    /** @param list<SocialLink> $links @param list<ProfileTab>|null $tabs */
    private function profile(
        EntityId $owner,
        ProfileVisibility $visibility,
        string $about = '',
        array $links = [],
        ?array $tabs = null,
    ): UserProfile {
        return new UserProfile(
            $owner,
            $about,
            null,
            null,
            $visibility,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            $links,
            $tabs ?? ProfileService::defaultTabs(),
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC'));
    }
}

final readonly class FixedViewerResolver implements ProfileViewerResolver
{
    public function __construct(private ?EntityId $viewerId)
    {
    }

    public function resolve(Request $request): ?EntityId
    {
        unset($request);
        return $this->viewerId;
    }
}

final class MemoryUserRepository implements UserRepository
{
    public function __construct(private User $user)
    {
    }

    public function find(EntityId $id): ?User
    {
        return $this->user->id()->equals($id) ? $this->user : null;
    }

    public function findByUsername(Username $username): ?User
    {
        return $this->user->username()->key() === $username->key() ? $this->user : null;
    }

    public function findByEmail(EmailAddress $email): ?User
    {
        return $this->user->email()->key() === $email->key() ? $this->user : null;
    }

    public function save(User $user): void
    {
        $this->user = $user;
    }

    public function history(EntityId $id, int $limit = 100, int $offset = 0): array
    {
        unset($id, $limit, $offset);
        return [];
    }
}

final class HttpMemoryProfileStore implements ProfileStore
{
    public function __construct(private ?UserProfile $profile = null)
    {
    }

    public function find(EntityId $userId): ?UserProfile
    {
        if ($this->profile === null || !$this->profile->userId->equals($userId)) {
            return null;
        }
        return $this->profile;
    }

    public function save(UserProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function updateMedia(EntityId $userId, ProfileMediaKind $kind, ?string $path): void
    {
        $current = $this->find($userId);
        if ($current === null) {
            throw new ProfileException('Test profile does not exist.');
        }
        $this->profile = new UserProfile(
            $current->userId,
            $current->about,
            $kind === ProfileMediaKind::Avatar ? $path : $current->avatarPath,
            $kind === ProfileMediaKind::Banner ? $path : $current->bannerPath,
            $current->profileVisibility,
            $current->aboutVisibility,
            $current->socialVisibility,
            $current->mediaVisibility,
            $current->socialLinks,
            $current->tabs,
            $current->updatedAt,
        );
    }
}

final class HttpMemoryStorageDriver implements StorageDriver
{
    /** @var array<string, string> */
    private array $objects = [];

    public function put(
        StoragePath $path,
        string $contents,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        $this->objects[$path->value()] = $contents;
        return new StoredObject($path, $visibility, strlen($contents), hash('sha256', $contents), $contentType);
    }

    public function putStream(
        StoragePath $path,
        ReadableStream $stream,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        return $this->put($path, $stream->contents(), $visibility, $contentType);
    }

    public function read(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): string
    {
        unset($visibility);
        return $this->objects[$path->value()] ?? throw new LogicException('Missing test object.');
    }

    public function readStream(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): ReadableStream {
        return ReadableStream::fromString($this->read($path, $visibility));
    }

    public function metadata(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): ?StoredObject {
        if (!isset($this->objects[$path->value()])) {
            return null;
        }
        $contents = $this->objects[$path->value()];
        return new StoredObject($path, $visibility, strlen($contents), hash('sha256', $contents));
    }

    public function exists(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        unset($visibility);
        return isset($this->objects[$path->value()]);
    }

    public function delete(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        unset($visibility);
        if (!isset($this->objects[$path->value()])) {
            return false;
        }
        unset($this->objects[$path->value()]);
        return true;
    }

    public function publicUrl(StoragePath $path): ?string
    {
        unset($path);
        return null;
    }
}
