<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (!is_string($path) || $path === '') {
    $path = '/';
}

$static = realpath($root . '/public' . $path);
$publicRoot = realpath($root . '/public');
if ($static !== false
    && $publicRoot !== false
    && str_starts_with($static, $publicRoot . DIRECTORY_SEPARATOR)
    && is_file($static)
) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/public/index.php';
require $root . '/public/index.php';
