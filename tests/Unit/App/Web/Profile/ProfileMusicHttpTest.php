<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileMusicHandler;
use Forwext\App\Web\Profile\ProfileViewHandler;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserId;
use Forwext\Core\Domain\User\UserLocale;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Domain\User\UserTimezone;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Profile\Music\BaselineProfileMusicPermissionResolver;
use Forwext\Core\Profile\Music\ProfileMusicExternalPolicy;
use Forwext\Core\Profile\Music\ProfileMusicModerationEvent;
use Forwext\Core\Profile\Music\ProfileMusicModerationStatus;
use Forwext\Core\Profile\Music\ProfileMusicService;
use Forwext\Core\Profile\Music\ProfileMusicSettings;
use Forwext\Core\Profile\Music\ProfileMusicSourceType;
use Forwext\Core\Profile\Music\ProfileMusicStore;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileStore;
use Forwext\Core\Profile\ProfileVisibility;
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

final class ProfileMusicHttpTest extends TestCase
{
    public function testUploadedMusicSupportsSingleByteRangeAndRejectsUnsatisfiableRange(): void
    {
        $user = $this->user('musicuser');
        $storage = new MusicHttpStorageDriver();
        $musicStore = new MusicHttpStore();
        $service = $this->service($user->id(), $musicStore, $storage);
        $payload = $this->mp3('range-payload');

        $service->replaceUpload($user->id(), $user->id(), $payload, $this->now());
        $service->updatePreferences(
            $user->id(),
            $user->id(),
            true,
            'Range test',
            ProfileVisibility::Public,
            70,
            false,
            false,
            true,
            $this->now(),
        );

        $handler = new ProfileMusicHandler(
            new MusicHttpUserRepository($user),
            $service,
            new MusicHttpViewerResolver(null),
        );

        $partial = $handler->handle($this->musicRequest(
            'musicuser',
            new HeaderBag(['Range' => 'bytes=2-6']),
        ));
        self::assertSame(206, $partial->status());
        self::assertSame(substr($payload, 2, 5), $partial->body());
        self::assertSame('bytes 2-6/' . strlen($payload), $partial->headers()->first('content-range'));
        self::assertSame('5', $partial->headers()->first('content-length'));
        self::assertSame('bytes', $partial->headers()->first('accept-ranges'));
        self::assertSame('private, no-store', $partial->headers()->first('cache-control'));

        $invalid = $handler->handle($this->musicRequest(
            'musicuser',
            new HeaderBag(['Range' => 'bytes=999999-']),
        ));
        self::assertSame(416, $invalid->status());
        self::assertSame('bytes */' . strlen($payload), $invalid->headers()->first('content-range'));
        self::assertSame('', $invalid->body());
    }

    public function testExternalPlayerRendersSafePreferencesWithoutAutoplayAttribute(): void
    {
        $user = $this->user('externaluser');
        $musicStore = new MusicHttpStore($this->externalSettings(
            $user->id(),
            'https://media.example.com/music/theme.ogg',
            '<Theme>',
            volume: 35,
            muted: true,
            autoplay: true,
            loop: true,
        ));
        $service = $this->service(
            $user->id(),
            $musicStore,
            new MusicHttpStorageDriver(),
            new ProfileMusicExternalPolicy(['media.example.com']),
            external: true,
        );
        $policy = new OwnerSafeProfileAccessPolicy();
        $profileStore = new MusicHttpProfileStore($this->profile($user->id()));
        $handler = new ProfileViewHandler(
            new MusicHttpUserRepository($user),
            new ProfileService($profileStore, $policy),
            $policy,
            new MusicHttpViewerResolver(null),
            new BasePath('/forum'),
            $service,
        );

        $response = $handler->handle($this->profileRequest('externaluser'));
        $body = $response->body();

        self::assertSame(200, $response->status());
        self::assertStringContainsString('data-profile-music', $body);
        self::assertStringContainsString('data-volume="35"', $body);
        self::assertStringContainsString('data-muted="1"', $body);
        self::assertStringContainsString('data-autoplay="1"', $body);
        self::assertStringContainsString('&lt;Theme&gt;', $body);
        self::assertStringContainsString('src="https://media.example.com/music/theme.ogg"', $body);
        self::assertStringContainsString('<audio controls preload="metadata" loop ', $body);
        self::assertStringContainsString('src="/forum/assets/profile-music.js"', $body);
        self::assertDoesNotMatchRegularExpression('/<audio[^>]*\sautoplay(?:\s|=|>)/i', $body);
    }

    public function testRevokedExternalHostHidesPlayerInsteadOfFailingProfile(): void
    {
        $user = $this->user('revokeduser');
        $musicStore = new MusicHttpStore($this->externalSettings(
            $user->id(),
            'https://old.example.com/music/theme.mp3',
            'Old source',
        ));
        $service = $this->service(
            $user->id(),
            $musicStore,
            new MusicHttpStorageDriver(),
            new ProfileMusicExternalPolicy(['new.example.com']),
            external: true,
        );
        $policy = new OwnerSafeProfileAccessPolicy();
        $handler = new ProfileViewHandler(
            new MusicHttpUserRepository($user),
            new ProfileService(new MusicHttpProfileStore($this->profile($user->id())), $policy),
            $policy,
            new MusicHttpViewerResolver(null),
            new BasePath(),
            $service,
        );

        $response = $handler->handle($this->profileRequest('revokeduser'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('revokeduser', $response->body());
        self::assertStringNotContainsString('data-profile-music', $response->body());
        self::assertStringNotContainsString('old.example.com', $response->body());
    }

    public function testCspMediaSrcContainsOnlyConfiguredExactHosts(): void
    {
        $root = sys_get_temp_dir() . '/forwext-profile-music-csp-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root . '/config', 0700, true));
        $config = [
            'profile_music' => [
                'external_allowed_hosts' => ['Media.Example.com.', 'cdn.example.net'],
            ],
        ];
        self::assertNotFalse(file_put_contents(
            $root . '/config/defaults.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n",
        ));

        try {
            $csp = (new WebApplicationFactory($root))->contentSecurityPolicy();
            self::assertStringContainsString(
                "media-src 'self' https://media.example.com https://cdn.example.net;",
                $csp,
            );
            self::assertStringNotContainsString('*.', $csp);
            self::assertStringContainsString("script-src 'self';", $csp);
            self::assertStringContainsString("object-src 'none';", $csp);
        } finally {
            @unlink($root . '/config/defaults.php');
            @rmdir($root . '/config');
            @rmdir($root);
        }
    }

    private function service(
        EntityId $owner,
        MusicHttpStore $musicStore,
        MusicHttpStorageDriver $storage,
        ?ProfileMusicExternalPolicy $externalPolicy = null,
        bool $external = false,
    ): ProfileMusicService {
        $policy = new OwnerSafeProfileAccessPolicy();
        return new ProfileMusicService(
            $musicStore,
            $storage,
            new ProfileService(new MusicHttpProfileStore($this->profile($owner)), $policy),
            $policy,
            new BaselineProfileMusicPermissionResolver(true, true, $external, true, false),
            $externalPolicy ?? new ProfileMusicExternalPolicy(),
        );
    }

    private function externalSettings(
        EntityId $owner,
        string $url,
        string $title,
        int $volume = 70,
        bool $muted = false,
        bool $autoplay = false,
        bool $loop = true,
    ): ProfileMusicSettings {
        return new ProfileMusicSettings(
            $owner,
            true,
            ProfileMusicSourceType::External,
            null,
            $url,
            $title,
            ProfileVisibility::Public,
            $volume,
            $muted,
            $autoplay,
            $loop,
            ProfileMusicModerationStatus::Active,
            null,
            null,
            null,
            $this->now(),
        );
    }

    private function profile(EntityId $owner): UserProfile
    {
        return new UserProfile(
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

    private function musicRequest(string $username, ?HeaderBag $headers = null): Request
    {
        return (new Request(
            HttpMethod::Get,
            '/members/' . rawurlencode($username) . '/music',
            $headers ?? new HeaderBag(),
        ))->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, ['username' => $username]);
    }

    private function profileRequest(string $username): Request
    {
        return (new Request(HttpMethod::Get, '/members/' . rawurlencode($username)))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, ['username' => $username]);
    }

    private function mp3(string $payload): string
    {
        return 'ID3' . str_repeat("\0", 16) . $payload;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-15 14:00:00', new DateTimeZone('UTC'));
    }
}

final readonly class MusicHttpViewerResolver implements ProfileViewerResolver
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

final class MusicHttpUserRepository implements UserRepository
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

final class MusicHttpProfileStore implements ProfileStore
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

final class MusicHttpStore implements ProfileMusicStore
{
    public function __construct(public ?ProfileMusicSettings $settings = null)
    {
    }

    public function find(EntityId $userId): ?ProfileMusicSettings
    {
        return $this->settings !== null && $this->settings->userId->equals($userId)
            ? $this->settings
            : null;
    }

    public function save(ProfileMusicSettings $settings): void
    {
        $this->settings = $settings;
    }

    public function saveModeration(ProfileMusicSettings $settings, ProfileMusicModerationEvent $event): void
    {
        unset($event);
        $this->settings = $settings;
    }
}

final class MusicHttpStorageDriver implements StorageDriver
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
