<?php

declare(strict_types=1);

use Forwext\App\Web\Community\CommunityApplicationFactory;
use Forwext\App\Web\ResponseEmitter;
use Forwext\App\Web\Seo\SeoApplicationFactory;
use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Migration\FileInstalledVersionStore;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

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
        $communityFactory = new CommunityApplicationFactory($root);
        $response = $communityFactory->handle($request);
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
} catch (Throwable) {
    $response = Response::text('Internal Server Error', 500)
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('X-Robots-Tag', 'noindex, nofollow')
        ->withHeader('Cache-Control', 'no-store');
}

(new ResponseEmitter())->emit($response, $requestMethod);
