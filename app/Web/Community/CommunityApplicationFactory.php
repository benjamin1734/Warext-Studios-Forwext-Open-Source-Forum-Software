<?php

declare(strict_types=1);

namespace Forwext\App\Web\Community;

use Forwext\App\Web\Profile\AuthSessionProfileViewerResolver;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Auth\Credential\DatabaseCredentialStore;
use Forwext\Core\Auth\Session\AuthSessionManager;
use Forwext\Core\Config\ConfigLoader;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Access\Permission\DatabasePermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\User\DatabaseUserRepository;
use Forwext\Core\Forum\Node\DatabaseForumNodeRepository;
use Forwext\Core\Forum\Stats\ForumStatsService;
use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Moderation\Discipline\DatabaseDisciplineAuthenticationAvailability;
use Forwext\Core\Presence\DatabasePresenceRepository;
use Forwext\Core\Presence\PresenceService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\RuntimeCanonicalUrlResolver;
use Forwext\Core\Search\Access\ForumSearchAccessScopeProvider;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretKey;
use Forwext\Core\Session\DatabaseSessionStore;
use Forwext\Core\Session\FileSessionStore;
use Forwext\Core\Session\SessionStore;
use RuntimeException;

final class CommunityApplicationFactory
{
    private ConfigRepository $config;
    private CanonicalUrl $canonicalUrl;
    private BasePath $basePath;
    private ?DatabaseConnection $database = null;
    private ?ProfileViewerResolver $viewers = null;
    private ?PresenceService $presence = null;
    private ?PermissionAuthorizer $authorizer = null;

    public function __construct(private string $projectRoot)
    {
        if ($this->projectRoot === '') {
            throw new RuntimeException('Project root cannot be empty.');
        }
        $this->config = (new ConfigLoader())->load(
            $this->projectRoot . '/config/defaults.php',
            $this->projectRoot . '/config/generated.php',
        );
        $this->canonicalUrl = new CanonicalUrl(RuntimeCanonicalUrlResolver::resolve(\n            $this->config->requireString('routing.canonical_url'),\n        ));
        $this->basePath = $this->canonicalUrl->basePath();
    }

    public function handle(Request $request): ?Response
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $routePath = $this->basePath->strip($path);
        if ($routePath === null) {
            return null;
        }

        if ($routePath === '/account/presence/heartbeat') {
            if ($request->method() !== HttpMethod::Post) {
                return Response::text('Method Not Allowed', 405)->withHeader('Allow', 'POST');
            }
            if ($request->cookie($this->config->requireString('authentication.session.cookie_name')) === null) {
                return (new Response('', 204))->withHeader('Cache-Control', 'no-store');
            }
            return (new PresenceHeartbeatHandler(
                $this->viewerResolver(),
                $this->presence(),
                new PresenceRequestGuard($this->canonicalUrl),
            ))->handle($request);
        }

        if ($routePath === '/account/presence') {
            if (!in_array($request->method(), [HttpMethod::Get, HttpMethod::Post], true)) {
                return Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET, POST');
            }
            return (new PresencePreferenceHandler(
                $this->viewerResolver(),
                $this->presence(),
                new PresenceRequestGuard($this->canonicalUrl),
            ))->handle($request);
        }

        if ($routePath === '/members/online') {
            if ($request->method() !== HttpMethod::Get) {
                return Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET');
            }
            return (new OnlineUsersHandler(
                $this->presence(),
                $this->viewerResolver(),
                $this->basePath,
            ))->handle($request);
        }

        if ($routePath === '/stats') {
            if ($request->method() !== HttpMethod::Get) {
                return Response::text('Method Not Allowed', 405)->withHeader('Allow', 'GET');
            }
            $stats = new ForumStatsService(
                $this->database(),
                new ForumSearchAccessScopeProvider(
                    new DatabaseForumNodeRepository($this->database()),
                    $this->permissionAuthorizer(),
                ),
            );
            return (new ForumStatsHandler(
                $stats,
                $this->presence(),
                $this->viewerResolver(),
                $this->basePath,
            ))->handle($request);
        }

        return null;
    }

    private function presence(): PresenceService
    {
        return $this->presence ??= new PresenceService(new DatabasePresenceRepository($this->database()));
    }

    private function viewerResolver(): ProfileViewerResolver
    {
        if ($this->viewers === null) {
            $this->viewers = new AuthSessionProfileViewerResolver(
                new AuthSessionManager(
                    $this->sessionStore(),
                    new DatabaseCredentialStore($this->database()),
                    $this->config->requireInt('authentication.session.ttl_seconds'),
                ),
                new DatabaseUserRepository($this->database()),
                $this->config->requireString('authentication.session.cookie_name'),
                new DatabaseDisciplineAuthenticationAvailability($this->database()),
            );
        }
        return $this->viewers;
    }

    private function permissionAuthorizer(): PermissionAuthorizer
    {
        return $this->authorizer ??= new PermissionAuthorizer(
            new PermissionEngine(new DatabasePermissionRuleRepository($this->database())),
            new DatabaseUserAccessAssignmentProvider($this->database()),
        );
    }

    private function sessionStore(): SessionStore
    {
        return match ($this->config->requireString('session.driver')) {
            'file' => new FileSessionStore($this->projectPath($this->config->requireString('session.path'))),
            'database' => new DatabaseSessionStore($this->database()),
            default => throw new RuntimeException(
                'Configured session driver requires an explicit advanced-runtime community composition.',
            ),
        };
    }

    private function database(): DatabaseConnection
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $secrets = new EncryptedFileSecretStore(
            $this->projectPath($this->config->requireString('security.secret_store_path')),
            new SecretCipher($this->masterKey()),
        );
        $password = $secrets->get($this->config->requireString('database.password_secret'));
        if ($password === null) {
            throw new RuntimeException('Database password secret is unavailable.');
        }
        $socket = $this->config->get('database.unix_socket');
        if ($socket !== null && !is_string($socket)) {
            throw new RuntimeException('Database unix socket configuration is invalid.');
        }
        $this->database = (new PdoConnectionFactory())->create(new DatabaseConfig(
            $this->config->requireString('database.host'),
            $this->config->requireInt('database.port'),
            $this->config->requireString('database.name'),
            $this->config->requireString('database.username'),
            $password,
            $this->config->requireString('database.charset'),
            $this->config->requireInt('database.connect_timeout_seconds'),
            $socket,
        ));
        return $this->database;
    }

    private function masterKey(): SecretKey
    {
        return (new EnvironmentOrFileSecretKeyProvider(
            $this->projectPath($this->config->requireString('security.master_key_file')),
            $this->config->requireString('security.master_key_environment'),
        ))->load();
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
