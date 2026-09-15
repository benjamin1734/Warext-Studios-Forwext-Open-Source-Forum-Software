<?php

declare(strict_types=1);

namespace Forwext\App\Web;

use Forwext\App\Web\Profile\AuthSessionProfileViewerResolver;
use Forwext\App\Web\Profile\MemberDirectoryHandler;
use Forwext\App\Web\Profile\ProfileMediaHandler;
use Forwext\App\Web\Profile\ProfileMusicHandler;
use Forwext\App\Web\Profile\ProfileViewHandler;
use Forwext\Core\Auth\Credential\DatabaseCredentialStore;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Config\ConfigLoader;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\User\DatabaseUserRepository;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Profile\DatabaseProfileStore;
use Forwext\Core\Profile\Music\BaselineProfileMusicPermissionResolver;
use Forwext\Core\Profile\Music\DatabaseProfileMusicStore;
use Forwext\Core\Profile\Music\ProfileMusicExternalPolicy;
use Forwext\Core\Profile\Music\ProfileMusicService;
use Forwext\Core\Profile\OwnerSafeProfileAccessPolicy;
use Forwext\Core\Profile\ProfileDirectoryReader;
use Forwext\Core\Profile\ProfileMediaKind;
use Forwext\Core\Profile\ProfileMediaService;
use Forwext\Core\Profile\ProfileService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\PathTemplate;
use Forwext\Core\Routing\Route;
use Forwext\Core\Routing\RouteCollection;
use Forwext\Core\Routing\Router;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Core\Session\FileSessionStore;
use Forwext\Core\Session\SessionStore;
use Forwext\Core\Storage\LocalStorageDriver;
use RuntimeException;

final readonly class WebApplicationFactory
{
    public function __construct(private string $projectRoot)
    {
        if ($projectRoot === '') {
            throw new RuntimeException('Project root cannot be empty.');
        }
    }

    public function create(string $version): Router
    {
        $config = $this->config();
        $database = $this->database($config);
        $users = new DatabaseUserRepository($database);
        $profileStore = new DatabaseProfileStore($database);
        $accessPolicy = new OwnerSafeProfileAccessPolicy();
        $profileService = new ProfileService($profileStore, $accessPolicy);
        $viewerResolver = new AuthSessionProfileViewerResolver(
            new AuthSessionManager(
                $this->sessionStore($config, $database),
                new DatabaseCredentialStore($database),
                $config->requireInt('authentication.session.ttl_seconds'),
            ),
            $users,
            $config->requireString('authentication.session.cookie_name'),
        );
        $storage = $this->localStorage($config);
        $mediaService = new ProfileMediaService($profileStore, $storage, $accessPolicy);
        $musicService = new ProfileMusicService(
            new DatabaseProfileMusicStore($database),
            $storage,
            $profileService,
            $accessPolicy,
            new BaselineProfileMusicPermissionResolver(
                $config->requireBool('profile_music.permissions.use'),
                $config->requireBool('profile_music.permissions.upload'),
                $config->requireBool('profile_music.permissions.external'),
                $config->requireBool('profile_music.permissions.autoplay'),
                $config->requireBool('profile_music.permissions.moderate'),
            ),
            $this->externalMusicPolicy($config),
            $config->requireInt('profile_music.upload_max_bytes'),
            $config->requireInt('profile_music.default_volume'),
        );
        $basePath = $this->basePath($config);

        $routes = new RouteCollection();
        $routes->add(new Route(
            'home',
            [HttpMethod::Get],
            new PathTemplate('/'),
            new HomeHandler($version, $basePath),
        ));
        $routes->add(new Route(
            'members.index',
            [HttpMethod::Get],
            new PathTemplate('/members'),
            new MemberDirectoryHandler(new ProfileDirectoryReader($database), $basePath),
        ));
        $routes->add(new Route(
            'members.profile',
            [HttpMethod::Get],
            new PathTemplate('/members/{username}'),
            new ProfileViewHandler(
                $users,
                $profileService,
                $accessPolicy,
                $viewerResolver,
                $basePath,
                $musicService,
            ),
        ));
        $routes->add(new Route(
            'members.avatar',
            [HttpMethod::Get],
            new PathTemplate('/members/{username}/avatar'),
            new ProfileMediaHandler($users, $mediaService, $viewerResolver, ProfileMediaKind::Avatar),
        ));
        $routes->add(new Route(
            'members.banner',
            [HttpMethod::Get],
            new PathTemplate('/members/{username}/banner'),
            new ProfileMediaHandler($users, $mediaService, $viewerResolver, ProfileMediaKind::Banner),
        ));
        $routes->add(new Route(
            'members.music',
            [HttpMethod::Get],
            new PathTemplate('/members/{username}/music'),
            new ProfileMusicHandler($users, $musicService, $viewerResolver),
        ));

        return new Router($routes, $basePath);
    }

    public function contentSecurityPolicy(): string
    {
        $sources = ["'self'"];
        foreach ($this->externalMusicPolicy($this->config())->allowedHosts() as $host) {
            $sources[] = 'https://' . $host;
        }

        return "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; media-src " . implode(' ', $sources) . '; '
            . "object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
    }

    private function config(): ConfigRepository
    {
        return (new ConfigLoader())->load(
            $this->projectRoot . '/config/defaults.php',
            $this->projectRoot . '/config/generated.php',
        );
    }

    private function database(ConfigRepository $config): DatabaseConnection
    {
        $keyProvider = new EnvironmentOrFileSecretKeyProvider(
            $this->projectPath($config->requireString('security.master_key_file')),
            $config->requireString('security.master_key_environment'),
        );
        $secrets = new EncryptedFileSecretStore(
            $this->projectPath($config->requireString('security.secret_store_path')),
            new SecretCipher($keyProvider->load()),
        );
        $password = $secrets->get($config->requireString('database.password_secret'));
        if ($password === null) {
            throw new RuntimeException('Database password secret is unavailable.');
        }

        $socket = $config->get('database.unix_socket');
        if ($socket !== null && !is_string($socket)) {
            throw new RuntimeException('Database unix socket configuration is invalid.');
        }

        return (new PdoConnectionFactory())->create(new DatabaseConfig(
            $config->requireString('database.host'),
            $config->requireInt('database.port'),
            $config->requireString('database.name'),
            $config->requireString('database.username'),
            $password,
            $config->requireString('database.charset'),
            $config->requireInt('database.connect_timeout_seconds'),
            $socket,
        ));
    }

    private function sessionStore(ConfigRepository $config, DatabaseConnection $database): SessionStore
    {
        return match ($config->requireString('session.driver')) {
            'file' => new FileSessionStore($this->projectPath($config->requireString('session.path'))),
            'database' => new DatabaseSessionStore($database),
            default => throw new RuntimeException(
                'Configured session driver requires an explicit advanced-runtime composition.',
            ),
        };
    }

    private function localStorage(ConfigRepository $config): LocalStorageDriver
    {
        if ($config->requireString('storage.driver') !== 'local') {
            throw new RuntimeException(
                'Configured storage driver requires an explicit advanced-runtime composition.',
            );
        }

        $baseUrl = $config->get('storage.local.public_base_url');
        if ($baseUrl !== null && !is_string($baseUrl)) {
            throw new RuntimeException('Public storage base URL configuration is invalid.');
        }

        return new LocalStorageDriver(
            $this->projectPath($config->requireString('storage.local.private_root')),
            $this->projectPath($config->requireString('storage.local.public_root')),
            $baseUrl,
        );
    }

    private function externalMusicPolicy(ConfigRepository $config): ProfileMusicExternalPolicy
    {
        $hosts = $config->get('profile_music.external_allowed_hosts', []);
        if (!is_array($hosts) || !array_is_list($hosts)) {
            throw new RuntimeException('Profile music external host allowlist must be a list.');
        }
        foreach ($hosts as $host) {
            if (!is_string($host)) {
                throw new RuntimeException('Profile music external host allowlist contains an invalid entry.');
            }
        }
        return new ProfileMusicExternalPolicy($hosts);
    }

    private function basePath(ConfigRepository $config): BasePath
    {
        $canonicalUrl = $config->requireString('routing.canonical_url');
        $path = parse_url($canonicalUrl, PHP_URL_PATH);
        if ($path === false) {
            throw new RuntimeException('Canonical URL path is invalid.');
        }

        $path = is_string($path) ? rtrim($path, '/') : '';
        return new BasePath($path);
    }

    private function projectPath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Configured project path is invalid.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1) {
            return $path;
        }

        return $this->projectRoot . '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}
