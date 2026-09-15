<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\CustomProfileUrlHandler;
use Forwext\App\Web\Profile\ProfileUrlSettingsHandler;
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
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Http\Security\Csrf\CsrfTokenManager;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Profile\ProfileStore;
use Forwext\Core\Profile\ProfileVisibility;
use Forwext\Core\Profile\Url\BaselineProfileUrlPermissionResolver;
use Forwext\Core\Profile\Url\ProfileSlug;
use Forwext\Core\Profile\Url\ProfileSlugPolicy;
use Forwext\Core\Profile\Url\ProfileUrlAssignment;
use Forwext\Core\Profile\Url\ProfileUrlException;
use Forwext\Core\Profile\Url\ProfileUrlResolution;
use Forwext\Core\Profile\Url\ProfileUrlService;
use Forwext\Core\Profile\Url\ProfileUrlStore;
use Forwext\Core\Profile\UserProfile;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use Forwext\Core\Security\Secret\SecretKey;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProfileUrlHttpTest extends TestCase
{
    public function testCurrentHistoricalAndCaseVariantRoutesUseOneCanonicalUrl(): void
    {
        $user = $this->user('urlowner');
        $profileStore = new ProfileUrlHttpProfileStore($this->profile($user->id(), ProfileVisibility::Public));
        $urlStore = new ProfileUrlHttpStore();
        $urlStore->seed($user->id(), 'current-slug', ['old-slug']);
        $policy = new OwnerSafeProfileAccessPolicy();
        $profiles = new ProfileService($profileStore, $policy);
        $viewers = new ProfileUrlHttpViewerResolver(null);
        $users = new ProfileUrlHttpUserRepository($user);
        $urls = $this->urlService($urlStore, $policy);
        $profilePage = new ProfileViewHandler(
            $users,
            $profiles,
            $policy,
            $viewers,
            new BasePath('/forum'),
        );
        $handler = new CustomProfileUrlHandler(
            $urls,
            $users,
            $profiles,
            $viewers,
            $profilePage,
            new BasePath('/forum'),
        );

        $current = $handler->handle($this->customRequest('current-slug'));
        self::assertSame(200, $current->status());
        self::assertStringContainsString('urlowner', $current->body());

        $old = $handler->handle($this->customRequest('old-slug'));
        self::assertSame(308, $old->status());
        self::assertSame('/forum/u/current-slug', $old->headers()->first('location'));
        self::assertSame('private, no-store', $old->headers()->first('cache-control'));

        $caseVariant = $handler->handle($this->customRequest('CURRENT-SLUG'));
        self::assertSame(308, $caseVariant->status());
        self::assertSame('/forum/u/current-slug', $caseVariant->headers()->first('location'));
    }

    public function testHistoricalSlugDoesNotLeakPrivateProfileCanonicalLocation(): void
    {
        $user = $this->user('privateurl');
        $profileStore = new ProfileUrlHttpProfileStore($this->profile($user->id(), ProfileVisibility::Private));
        $urlStore = new ProfileUrlHttpStore();
        $urlStore->seed($user->id(), 'private-current', ['private-old']);
        $policy = new OwnerSafeProfileAccessPolicy();
        $profiles = new ProfileService($profileStore, $policy);
        $viewers = new ProfileUrlHttpViewerResolver(null);
        $users = new ProfileUrlHttpUserRepository($user);
        $handler = new CustomProfileUrlHandler(
            $this->urlService($urlStore, $policy),
            $users,
            $profiles,
            $viewers,
            new ProfileViewHandler($users, $profiles, $policy, $viewers, new BasePath()),
            new BasePath(),
        );

        $response = $handler->handle($this->customRequest('private-old'));
        self::assertSame(404, $response->status());
        self::assertSame('Not Found', $response->body());
        self::assertNull($response->headers()->first('location'));
    }

    public function testSettingsPostRequiresCsrfAndValidRoundTripUpdatesOwnerSlug(): void
    {
        $owner = $this->user('settingsowner');
        $store = new ProfileUrlHttpStore();
        $policy = new OwnerSafeProfileAccessPolicy();
        $service = $this->urlService($store, $policy);
        $handler = new ProfileUrlSettingsHandler(
            $service,
            new ProfileUrlHttpViewerResolver($owner->id()),
            new BasePath(),
        );
        $cookieName = 'forwext_csrf_test';
        $csrf = new CsrfMiddleware(
            new CsrfTokenManager(SecretKey::generate(), 7200),
            'profile-url',
            $cookieName,
            false,
            7200,
        );
        $routes = new RouteCollection();
        $routes->add(new Route(
            'account.profile-url.test',
            [HttpMethod::Get, HttpMethod::Post],
            new PathTemplate('/account/profile-url'),
            $handler,
            [$csrf],
        ));
        $router = new Router($routes);

        $get = $router->handle(new Request(HttpMethod::Get, '/account/profile-url'));
        self::assertSame(200, $get->status());
        self::assertStringContainsString('Özel profil URL', $get->body());
        $setCookie = $get->headers()->first('set-cookie');
        self::assertNotNull($setCookie);
        self::assertMatchesRegularExpression('/^forwext_csrf_test=[a-f0-9]{64};/', $setCookie);
        self::assertMatchesRegularExpression('/name="_csrf" value="([^"]+)"/', $get->body());

        $withoutCsrf = $router->handle(new Request(
            HttpMethod::Post,
            '/account/profile-url',
            new HeaderBag(),
            [],
            ['slug' => 'my-profile'],
        ));
        self::assertSame(403, $withoutCsrf->status());
        self::assertNull($store->findCurrent($owner->id()));

        self::assertSame(1, preg_match('/name="_csrf" value="([^"]+)"/', $get->body(), $tokenMatch));
        $token = html_entity_decode($tokenMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cookiePair = explode(';', $setCookie, 2)[0];
        [, $cookieValue] = explode('=', $cookiePair, 2);

        $valid = $router->handle(new Request(
            HttpMethod::Post,
            '/account/profile-url',
            new HeaderBag(),
            [],
            ['_csrf' => $token, 'slug' => 'My-Profile'],
            '',
            [$cookieName => $cookieValue],
        ));
        self::assertSame(303, $valid->status());
        self::assertSame('/account/profile-url?updated=1', $valid->headers()->first('location'));
        self::assertSame('my-profile', $store->findCurrent($owner->id())?->slug->value());
    }

    private function urlService(ProfileUrlHttpStore $store, OwnerSafeProfileAccessPolicy $policy): ProfileUrlService
    {
        return new ProfileUrlService(
            $store,
            $policy,
            new BaselineProfileUrlPermissionResolver(true),
            new ProfileSlugPolicy(['admin', 'system', 'forwext']),
            86400,
            2592000,
            3,
        );
    }

    private function customRequest(string $slug): Request
    {
        return (new Request(HttpMethod::Get, '/u/' . rawurlencode($slug)))
            ->withAttribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, ['slug' => $slug]);
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

    private function profile(EntityId $owner, ProfileVisibility $visibility): UserProfile
    {
        return new UserProfile(
            $owner,
            'profile about',
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
        return new DateTimeImmutable('2026-09-15 16:00:00', new DateTimeZone('UTC'));
    }
}

final readonly class ProfileUrlHttpViewerResolver implements ProfileViewerResolver
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

final class ProfileUrlHttpUserRepository implements UserRepository
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

final class ProfileUrlHttpProfileStore implements ProfileStore
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
        unset($kind, $path);
        if (!$this->profile->userId->equals($userId)) {
            throw new LogicException('Wrong profile owner.');
        }
    }
}

final class ProfileUrlHttpStore implements ProfileUrlStore
{
    /** @var array<string, ProfileUrlAssignment> */
    private array $current = [];

    /** @var array<string, array{user:EntityId,current:string,is_current:bool}> */
    private array $claims = [];

    public function findCurrent(EntityId $userId): ?ProfileUrlAssignment
    {
        return $this->current[$userId->value()] ?? null;
    }

    public function resolve(ProfileSlug $slug): ?ProfileUrlResolution
    {
        $claim = $this->claims[$slug->value()] ?? null;
        if ($claim === null) {
            return null;
        }

        return new ProfileUrlResolution(
            $claim['user'],
            $slug,
            ProfileSlug::fromString($claim['current']),
            $claim['is_current'],
        );
    }

    public function claim(
        EntityId $userId,
        ProfileSlug $slug,
        DateTimeImmutable $now,
        int $minimumChangeIntervalSeconds,
        int $changeWindowSeconds,
        int $maximumChangesPerWindow,
    ): ProfileUrlAssignment {
        unset($minimumChangeIntervalSeconds, $changeWindowSeconds, $maximumChangesPerWindow);
        if (isset($this->claims[$slug->value()])) {
            $existing = $this->claims[$slug->value()];
            if (!$existing['user']->equals($userId) || !$existing['is_current']) {
                throw new ProfileUrlException('Custom profile slug is unavailable.');
            }
            return $this->current[$userId->value()];
        }

        $previous = $this->current[$userId->value()] ?? null;
        if ($previous !== null) {
            $this->claims[$previous->slug->value()]['is_current'] = false;
        }
        $utc = ProfileUrlAssignment::utc($now);
        $assignment = new ProfileUrlAssignment(
            $userId,
            $slug,
            $utc,
            $previous?->windowStartedAt ?? $utc,
            $previous === null ? 0 : $previous->changesInWindow + 1,
        );
        $this->current[$userId->value()] = $assignment;
        $this->claims[$slug->value()] = [
            'user' => $userId,
            'current' => $slug->value(),
            'is_current' => true,
        ];
        foreach ($this->claims as &$claim) {
            if ($claim['user']->equals($userId)) {
                $claim['current'] = $slug->value();
            }
        }
        unset($claim);
        return $assignment;
    }

    /** @param list<string> $history */
    public function seed(EntityId $userId, string $current, array $history = []): void
    {
        $time = new DateTimeImmutable('2026-09-15 15:00:00', new DateTimeZone('UTC'));
        $currentSlug = ProfileSlug::fromString($current);
        $this->current[$userId->value()] = new ProfileUrlAssignment($userId, $currentSlug, $time, $time, count($history));
        $this->claims[$currentSlug->value()] = [
            'user' => $userId,
            'current' => $currentSlug->value(),
            'is_current' => true,
        ];
        foreach ($history as $historical) {
            $slug = ProfileSlug::fromString($historical);
            $this->claims[$slug->value()] = [
                'user' => $userId,
                'current' => $currentSlug->value(),
                'is_current' => false,
            ];
        }
    }
}
