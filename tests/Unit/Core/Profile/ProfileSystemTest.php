<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
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
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Storage\StoredObject;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProfileSystemTest extends TestCase
{
    public function testVisibilityPolicyIsFailClosedForGuestsAndNonOwners(): void
    {
        $owner = UserId::generate();
        $viewer = UserId::generate();
        $policy = new OwnerSafeProfileAccessPolicy();

        self::assertTrue($policy->canViewProfile($this->profile($owner, ProfileVisibility::Public), null));
        self::assertFalse($policy->canViewProfile($this->profile($owner, ProfileVisibility::Members), null));
        self::assertTrue($policy->canViewProfile($this->profile($owner, ProfileVisibility::Members), $viewer));
        self::assertFalse($policy->canViewProfile($this->profile($owner, ProfileVisibility::Private), $viewer));
        self::assertTrue($policy->canViewProfile($this->profile($owner, ProfileVisibility::Private), $owner));
        self::assertFalse($policy->canEdit($owner, $viewer));
        self::assertTrue($policy->canEdit($owner, $owner));
    }

    public function testProfileUpdateRequiresOwnerAndValidatesTypedContent(): void
    {
        $owner = UserId::generate();
        $attacker = UserId::generate();
        $store = new MemoryProfileStore();
        $service = new ProfileService($store);

        $this->expectException(ProfileException::class);
        $service->update(
            $owner,
            $attacker,
            'Denied',
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            [],
            ProfileService::defaultTabs(),
            $this->now(),
        );
    }

    public function testOwnerCanPersistAboutSocialLinksAndTabs(): void
    {
        $owner = UserId::generate();
        $store = new MemoryProfileStore();
        $service = new ProfileService($store);
        $links = [new SocialLink('github', 'https://github.com/example')];
        $tabs = [new ProfileTab('about', true, ProfileVisibility::Members, 10)];

        $profile = $service->update(
            $owner,
            $owner,
            'Forwext member',
            ProfileVisibility::Members,
            ProfileVisibility::Members,
            ProfileVisibility::Public,
            ProfileVisibility::Private,
            $links,
            $tabs,
            $this->now(),
        );

        self::assertSame('Forwext member', $profile->about);
        self::assertSame(ProfileVisibility::Members, $profile->profileVisibility);
        self::assertSame('github', $profile->socialLinks[0]->key);
        self::assertSame('about', $profile->tabs[0]->key);
        self::assertSame($profile, $store->profile);
    }

    public function testSocialLinksRejectInsecureOrCredentialBearingUrls(): void
    {
        $this->expectException(ProfileException::class);
        new SocialLink('github', 'http://user:pass@example.com/profile');
    }

    public function testMediaMutationRequiresOwnerBeforePayloadProcessing(): void
    {
        $owner = UserId::generate();
        $attacker = UserId::generate();
        $store = new MemoryProfileStore($this->profile($owner, ProfileVisibility::Public));
        $service = new ProfileMediaService($store, new MemoryStorageDriver());

        $this->expectException(ProfileException::class);
        $service->replace($owner, $attacker, ProfileMediaKind::Avatar, 'not-an-image');
    }

    public function testMediaRejectsNonImagePayloadForOwner(): void
    {
        $owner = UserId::generate();
        $store = new MemoryProfileStore($this->profile($owner, ProfileVisibility::Public));
        $service = new ProfileMediaService($store, new MemoryStorageDriver());

        $this->expectException(ProfileException::class);
        $service->replace($owner, $owner, ProfileMediaKind::Avatar, 'not-an-image');
    }

    private function profile(EntityId $owner, ProfileVisibility $visibility): UserProfile
    {
        return new UserProfile(
            $owner,
            '',
            null,
            null,
            $visibility,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            [],
            ProfileService::defaultTabs(),
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15 12:00:00', new DateTimeZone('UTC'));
    }
}

final class MemoryProfileStore implements ProfileStore
{
    public function __construct(public ?UserProfile $profile = null)
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
        $current = $this->profile;
        if ($current === null || !$current->userId->equals($userId)) {
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

final class MemoryStorageDriver implements StorageDriver
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
        return new StoredObject(
            $path,
            $visibility,
            strlen($contents),
            hash('sha256', $contents),
            $contentType,
        );
    }

    public function putStream(
        StoragePath $path,
        ReadableStream $stream,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        return $this->put($path, $stream->contents(), $visibility, $contentType);
    }

    public function read(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): string {
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
        return new StoredObject(
            $path,
            $visibility,
            strlen($contents),
            hash('sha256', $contents),
        );
    }

    public function exists(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): bool {
        unset($visibility);
        return isset($this->objects[$path->value()]);
    }

    public function delete(
        StoragePath $path,
        StorageVisibility $visibility = StorageVisibility::Private,
    ): bool {
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
