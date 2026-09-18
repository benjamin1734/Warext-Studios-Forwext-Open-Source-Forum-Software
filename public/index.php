<?php

declare(strict_types=1);

use Forwext\App\Web\Community\CommunityApplicationFactory;
use Forwext\App\Web\Moderation\ModerationApplicationFactory;
use Forwext\App\Web\Report\ReportApplicationFactory;
use Forwext\App\Web\ResponseEmitter;
use Forwext\App\Web\Seo\SeoApplicationFactory;
use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Migration\FileInstalledVersionStore;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="tr"><meta charset="utf-8"><title>Forwext sunucu gereksinimi</title>';
    echo '<body style="font:16px/1.55 system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px">';
    echo '<h1>PHP 8.4 veya üzeri gerekli</h1>';
    echo '<p>Bu Forwext sürümü PHP 8.4+ gerektirir. Sunucuda çalışan PHP sürümü: <strong>'
        . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
    echo '<p>cPanel kullanıyorsanız MultiPHP Manager üzerinden bu alan adını PHP 8.4 veya daha yeni bir sürüme alın.</p>';
    echo '</body></html>';
    exit;
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; media-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>Forwext package incomplete</h1><p>vendor/autoload.php is missing. Use the generated installation ZIP.</p>';
    exit;
}

require $autoload;

$versions = new FileInstalledVersionStore($root . '/storage/install/installed-version.json');
$version = $versions->current();
if ($version === null) {
    header('Location: install.php', true, 302);
    exit;
}

$requestMethod = HttpMethod::Get;
try {
    $request = Request::fromGlobals();
    $requestMethod = $request->method();

    $seoFactory = new SeoApplicationFactory($root);
    $response = $seoFactory->handle($request);

    if ($response === null) {
        $moderationFactory = new ModerationApplicationFactory($root);
        $response = $moderationFactory->handle($request);

        if ($response === null) {
            $reportFactory = new ReportApplicationFactory($root);
            $response = $reportFactory->handle($request);
        }

        if ($response === null) {
            $communityFactory = new CommunityApplicationFactory($root);
            $response = $communityFactory->handle($request);
        }

        $webFactory = new WebApplicationFactory($root);
        if ($response === null) {
            $application = $webFactory->create($version->value());
            $response = $application->handle($request);
        }

        $response = $seoFactory->decorate($request, $response)
            ->withHeader('Content-Security-Policy', $webFactory->contentSecurityPolicy());
    }

    $response = $response
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'no-referrer');
} catch (Throwable $exception) {
    $response = Response::text('Internal Server Error', 500)
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Robots-Tag', 'noindex, nofollow')
        ->withHeader('Cache-Control', 'no-store');
}

(new ResponseEmitter())->emit($response, $requestMethod);
