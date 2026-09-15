<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile\Music;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Profile\Music\BaselineProfileMusicPermissionResolver;
use Forwext\Core\Profile\Music\ProfileMusicExternalPolicy;
use Forwext\Core\Profile\Music\ProfileMusicModerationEvent;
use Forwext\Core\Profile\Music\ProfileMusicPermission;
use Forwext\Core\Profile\Music\ProfileMusicPermissionResolver;
use Forwext\Core\Profile\Music\ProfileMusicService;
use Forwext\Core\Profile\Music\ProfileMusicSettings;
use Forwext\Core\Profile\Music\ProfileMusicSourceType;
use Forwext\Core\Profile\Music\ProfileMusicStore;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\ProfileException;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileStore;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Storage\ReadableStream;
use Forwext\Core\Storage\StorageDriver;
use Forwext\Core\Storage\StoragePath;
use Forwext\Core\Storage\StorageVisibility;
use Forwext\Core\Storage\StoredObject;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProfileMusicSystemTest extends TestCase
{
    public function testBaselinePermissionsKeepExternalAndModerationDisabled(): void
    {
        $userId = UserId::generate();
        $resolver = new BaselineProfileMusicPermissionResolver();

        self::assertTrue($resolver->allows($userId, ProfileMusicPermission::Use));
        self::assertTrue($resolver->allows($userId, ProfileMusicPermission::Upload));
        self::assertTrue($resolver->allows($userId, ProfileMusicPermission::Autoplay));
        self::assertFalse($resolver->allows($userId, ProfileMusicPermission::External));
        self::assertFalse($resolver->allows($userId, ProfileMusicPermission::Moderate));
    }

    public function testExternalPolicyUsesExactPublicHttpsAllowlist(): void
    {
        $policy = new ProfileMusicExternalPolicy(['CDN.Example.com.']);
        self::assertSame(
            'https://cdn.example.com/audio/song.mp3?a=1',
            $policy->normalize('https://CDN.Example.com/audio/song.mp3?a=1'),
        );

        $this->expectException(ProfileException::class);
        $policy->normalize('https://sub.cdn.example.com/audio/song.mp3');
    }

    public function testUploadIsPrivateContentAddressedAndOldObjectIsRemoved(): void
    {
        $owner = UserId::generate();
        $storage = new MusicMemoryStorageDriver();
        $permissions = new MutableMusicPermissionResolver();
        $service = $this->service($owner, $storage, $permissions);

        $first = $service->replaceUpload($owner, $owner, $this->mp3('first'), $this->now());
        self::assertSame(ProfileMusicSourceType::Upload, $first->sourceType);
        self::assertNotNull($first->uploadPath);
        self::assertTrue($storage->exists(StoragePath::fromString($first->uploadPath), StorageVisibility::Private));
        self::assertSame(StorageVisibility::Private, $storage->lastVisibility);

        $oldPath = $first->uploadPath;
        $second = $service->replaceUpload($owner, $owner, $this->mp3('second'), $this->now()->modify('+1 second'));
        self::assertNotSame($oldPath, $second->uploadPath);
        self::assertFalse($storage->exists(StoragePath::fromString($oldPath), StorageVisibility::Private));
    }

    public function testPlaybackRespectsMusicVisibilityAndRuntimePermissionChanges(): void
    {
        $owner = UserId::generate();
        $viewer = UserId::generate();
        $storage = new MusicMemoryStorageDriver();
        $permissions = new MutableMusicPermissionResolver();
        $service = $this->service($owner, $storage, $permissions);

        $service->replaceUpload($owner, $owner, $this->mp3('private'), $this->now());
        $service->updatePreferences(
            $owner,
            $owner,
            true,
            'Private song',
            ProfileVisibility::Private,
            65,
            false,
            false,
            true,
            $this->now(),
        );

        self::assertNull($service->visiblePlayback($owner, null, $this->now()));
        self::assertNull($service->visiblePlayback($owner, $viewer, $this->now()));
        self::assertNotNull($service->visiblePlayback($owner, $owner, $this->now()));

        $permissions->set(ProfileMusicPermission::Upload, false);
        self::assertNull($service->visiblePlayback($owner, $owner, $this->now()));
    }

    public function testAutoplayPreferenceRequiresAutoplayPermission(): void
    {
        $owner = UserId::generate();
        $permissions = new MutableMusicPermissionResolver();
        $permissions->set(ProfileMusicPermission::Autoplay, false);
        $service = $this->service($owner, new MusicMemoryStorageDriver(), $permissions);

        $this->expectException(ProfileException::class);
        $service->updatePreferences(
            $owner,
            $owner,
            true,
            'Song',
            ProfileVisibility::Public,
            70,
            false,
            true,
            true,
            $this->now(),
        );
    }

    public function testExternalSourceRequiresBothPermissionAndAllowlistedHost(): void
    {
        $owner = UserId::generate();
        $permissions = new MutableMusicPermissionResolver();
        $permissions->set(ProfileMusicPermission::External, true);
        $service = $this->service(
            $owner,
            new MusicMemoryStorageDriver(),
            $permissions,
            new ProfileMusicExternalPolicy(['media.example.com']),
        );

        $settings = $service->setExternalSource(
            $owner,
            $owner,
            'https://media.example.com/music/theme.ogg',
            $this->now(),
        );
        self::assertSame(ProfileMusicSourceType::External, $settings->sourceType);
        self::assertSame('https://media.example.com/music/theme.ogg', $settings->externalUrl);
    }

    public function testModerationRequiresPermissionPersistsAuditAndBlocksPlayback(): void
    {
        $owner = UserId::generate();
        $moderator = UserId::generate();
        $musicStore = new MusicMemoryStore();
        $storage = new MusicMemoryStorageDriver();
        $permissions = new MutableMusicPermissionResolver();
        $permissions->set(ProfileMusicPermission::Moderate, true);
        $service = $this->service($owner, $storage, $permissions, musicStore: $musicStore);
        $service->replaceUpload($owner, $owner, $this->mp3('moderated'), $this->now());
        $service->updatePreferences(
            $owner,
            $owner,
            true,
            'Theme',
            ProfileVisibility::Public,
            70,
            false,
            false,
            true,
            $this->now(),
        );

        $blocked = $service->moderate($owner, $moderator, true, 'policy.audio', $this->now());
        self::assertSame('policy.audio', $blocked->moderationReasonCode);
        self::assertCount(1, $musicStore->events);
        self::assertNull($service->visiblePlayback($owner, null, $this->now()));

        $service->moderate($owner, $moderator, false, null, $this->now()->modify('+1 minute'));
        self::assertCount(2, $musicStore->events);
        self::assertNotNull($service->visiblePlayback($owner, null, $this->now()->modify('+1 minute')));
    }

    public function testInvalidUploadPayloadIsRejected(): void
    {
        $owner = UserId::generate();
        $service = $this->service($owner, new MusicMemoryStorageDriver(), new MutableMusicPermissionResolver());

        $this->expectException(ProfileException::class);
        $service->replaceUpload($owner, $owner, '<svg>not audio</svg>', $this->now());
    }

    private function service(
        EntityId $owner,
        MusicMemoryStorageDriver $storage,
        MutableMusicPermissionResolver $permissions,
        ?ProfileMusicExternalPolicy $externalPolicy = null,
        ?MusicMemoryStore $musicStore = null,
    ): ProfileMusicService {
        $policy = new OwnerSafeProfileAccessPolicy();
        $profile = new UserProfile(
            $owner,
            '',
            null,
            null,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            ProfileVisibility::Public,
            [],
            ProfileService::defaultTabs(),
            $this->now(),
        );
        $profileStore = new MusicMemoryProfileStore($profile);

        return new ProfileMusicService(
            $musicStore ?? new MusicMemoryStore(),
            $storage,
            new ProfileService($profileStore, $policy),
            $policy,
            $permissions,
            $externalPolicy ?? new ProfileMusicExternalPolicy(),
        );
    }

    private function mp3(string $payload): string
    {
        return 'ID3' . str_repeat("\0", 16) . $payload;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15 13:40:00', new DateTimeZone('UTC'));
    }
}

final class MutableMusicPermissionResolver implements ProfileMusicPermissionResolver
{
    /** @var array<string, bool> */
    private array $values = [
        'profile.music.use' => true,
        'profile.music.upload' => true,
        'profile.music.external' => false,
        'profile.music.autoplay' => true,
        'profile.music.moderate' => false,
    ];

    public function allows(EntityId $userId, ProfileMusicPermission $permission): bool
    {
        UserId::assert($userId);
        return $this->values[$permission->value] ?? false;
    }

    public function set(ProfileMusicPermission $permission, bool $allowed): void
    {
        $this->values[$permission->value] = $allowed;
    }
}

final class MusicMemoryStore implements ProfileMusicStore
{
    public ?ProfileMusicSettings $settings = null;

    /** @var list<ProfileMusicModerationEvent> */
    public array $events = [];

    public function find(EntityId $userId): ?ProfileMusicSettings
    {
        if ($this->settings === null || !$this->settings->userId->equals($userId)) {
            return null;
        }
        return $this->settings;
    }

    public function save(ProfileMusicSettings $settings): void
    {
        $this->settings = $settings;
    }

    public function saveModeration(ProfileMusicSettings $settings, ProfileMusicModerationEvent $event): void
    {
        $this->settings = $settings;
        $this->events[] = $event;
    }
}

final class MusicMemoryProfileStore implements ProfileStore
{
    public function __construct(private UserProfile $profile)
    {
    }

    public function find(EntityId $userId): ?UserProfile
    {
        return $this->profile->userId->equals($userId) ? $this->profile : null;
    }

    public function save(UserProfile $profile): void
    {
        $this->profile = $profile;
    }

    public function updateMedia(EntityId $userId, ProfileMediaKind $kind, ?string $path): void
    {
        if (!$this->profile->userId->equals($userId)) {
            throw new LogicException('Wrong profile.');
        }
        $this->profile = new UserProfile(
            $this->profile->userId,
            $this->profile->about,
            $kind === ProfileMediaKind::Avatar ? $path : $this->profile->avatarPath,
            $kind === ProfileMediaKind::Banner ? $path : $this->profile->bannerPath,
            $this->profile->profileVisibility,
            $this->profile->aboutVisibility,
            $this->profile->socialVisibility,
            $this->profile->mediaVisibility,
            $this->profile->socialLinks,
            $this->profile->tabs,
            $this->profile->updatedAt,
        );
    }
}

final class MusicMemoryStorageDriver implements StorageDriver
{
    /** @var array<string, string> */
    private array $objects = [];

    public ?StorageVisibility $lastVisibility = null;

    public function put(
        StoragePath $path,
        string $contents,
        StorageVisibility $visibility = StorageVisibility::Private,
        ?string $contentType = null,
    ): StoredObject {
        $this->lastVisibility = $visibility;
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
        return $this->objects[$path->value()] ?? throw new LogicException('Missing test music object.');
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
        return isset($this->objects[$path->value()]);
    }

    public function delete(StoragePath $path, StorageVisibility $visibility = StorageVisibility::Private): bool
    {
        if (!isset($this->objects[$path->value()])) {
            return false;
        }
        unset($this->objects[$path->value()]);
        return true;
    }

    public function publicUrl(StoragePath $path): ?string
    {
        return null;
    }
}
