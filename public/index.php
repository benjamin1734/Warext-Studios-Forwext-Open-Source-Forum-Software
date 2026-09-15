<?php

declare(strict_types=1);

use Forwext\App\Web\ResponseEmitter;
use Forwext\App\Web\WebApplicationFactory;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Migration\FileInstalledVersionStore;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

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
    $application = (new WebApplicationFactory($root))->create($version->value());
    $response = $application->handle($request)
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Referrer-Policy', 'no-referrer')
        ->withHeader(
            'Content-Security-Policy',
            "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
            . "object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
        );
} catch (Throwable) {
    $response = Response::text('Internal Server Error', 500)
        ->withHeader('X-Content-Type-Options', 'nosniff')
        ->withHeader('Cache-Control', 'no-store');
}

(new ResponseEmitter())->emit($response, $requestMethod);
