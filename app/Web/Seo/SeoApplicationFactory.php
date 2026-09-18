<?php

declare(strict_types=1);

namespace Forwext\App\Web\Seo;

use Forwext\Core\Config\ConfigLoader;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Database\DatabaseConfig;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Database\PdoConnectionFactory;
use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Faq\Seo\DatabaseFaqPublicDiscoverySource;
use Forwext\Core\Faq\Seo\DatabaseFaqSeoReader;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Security\Secret\EncryptedFileSecretStore;
use Forwext\Core\Security\Secret\EnvironmentOrFileSecretKeyProvider;
use Forwext\Core\Security\Secret\SecretCipher;
use Forwext\Core\Security\Secret\SecretKey;
use Forwext\Core\Seo\Discovery\DatabasePublicProfileDiscoverySource;
use Forwext\Core\Seo\Discovery\DatabasePublicProfileSeoReader;
use Forwext\Core\Seo\Discovery\PublicDiscoveryService;
use Forwext\Core\Seo\Discovery\StaticPublicDiscoverySource;
use Forwext\Core\Seo\SeoContext;
use Forwext\Core\Routing\RuntimeCanonicalUrlResolver;
use RuntimeException;

final class SeoApplicationFactory
{
    private ConfigRepository $config;
    private SeoContext $context;
    private ?DatabaseConnection $database = null;
    private ?DatabasePublicProfileSeoReader $profiles = null;
    private ?DatabaseFaqSeoReader $faq = null;
    private ?PublicDiscoveryService $discovery = null;

    public function __construct(private string $projectRoot)
    {
        if ($this->projectRoot === '') {
            throw new RuntimeException('Project root cannot be empty.');
        }

        $this->config = (new ConfigLoader())->load(
            $this->projectRoot . '/config/defaults.php',
            $this->projectRoot . '/config/generated.php',
        );
        $canonical = new CanonicalUrl(RuntimeCanonicalUrlResolver::resolve(\n            $this->config->requireString('routing.canonical_url'),\n        ));
        $siteName = $this->config->get('seo.site_name', 'Forwext');
        $siteDescription = $this->config->get(
            'seo.site_description',
            'Forwext — Open Source Forum Platform',
        );
        if (!is_string($siteName) || !is_string($siteDescription)) {
            throw new RuntimeException('SEO site metadata configuration is invalid.');
        }

        $this->context = new SeoContext($canonical, $siteName, $siteDescription);
    }

    public function handle(Request $request): ?Response
    {
        if ($request->method() !== HttpMethod::Get) {
            return null;
        }

        $path = parse_url($request->uri(), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $routePath = $this->context->basePath()->strip($path);
        if ($routePath === null) {
            return null;
        }

        return match ($routePath) {
            '/robots.txt' => (new RobotsHandler($this->context))->handle($request),
            '/sitemap.xml' => (new SitemapHandler($this->discovery(), $this->context))->handle($request),
            '/feed.rss' => (new FeedHandler(
                $this->discovery(),
                $this->context,
                FeedFormat::Rss,
            ))->handle($request),
            '/feed.atom' => (new FeedHandler(
                $this->discovery(),
                $this->context,
                FeedFormat::Atom,
            ))->handle($request),
            default => null,
        };
    }

    public function decorate(Request $request, Response $response): Response
    {
        $path = parse_url($request->uri(), PHP_URL_PATH);
        $routePath = is_string($path) && $path !== ''
            ? $this->context->basePath()->strip($path)
            : null;
        $needsProfileLookup = is_string($routePath)
            && (
                preg_match('#^/members/[^/]+$#D', $routePath) === 1
                || preg_match('#^/u/[^/]+$#D', $routePath) === 1
            );
        $needsFaqLookup = is_string($routePath)
            && preg_match('#^/faq/[^/]+/[^/]+$#D', $routePath) === 1;

        return (new SeoResponseDecorator(
            $this->context,
            $needsProfileLookup ? $this->profiles() : null,
            $needsFaqLookup ? $this->faq() : null,
        ))->decorate($request, $response);
    }

    private function discovery(): PublicDiscoveryService
    {
        if ($this->discovery === null) {
            $this->discovery = new PublicDiscoveryService([
                new StaticPublicDiscoverySource(),
                new DatabasePublicProfileDiscoverySource($this->profiles()),
                new DatabaseFaqPublicDiscoverySource($this->database()),
            ]);
        }

        return $this->discovery;
    }

    private function faq(): DatabaseFaqSeoReader
    {
        if ($this->faq === null) {
            $this->faq = new DatabaseFaqSeoReader($this->database());
        }

        return $this->faq;
    }

    private function profiles(): DatabasePublicProfileSeoReader
    {
        if ($this->profiles === null) {
            $this->profiles = new DatabasePublicProfileSeoReader($this->database());
        }

        return $this->profiles;
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
