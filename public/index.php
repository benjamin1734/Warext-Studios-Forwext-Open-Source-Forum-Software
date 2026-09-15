<?php

declare(strict_types=1);

use Forwext\Core\Migration\FileInstalledVersionStore;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");

if (!is_file($autoload)) {
    http_response_code(500);
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

$safeVersion = htmlspecialchars($version->value(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forwext</title><style>:root{color-scheme:dark}body{margin:0;background:#0d1117;color:#e6edf3;font:16px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;display:grid;min-height:100vh;place-items:center}.card{width:min(720px,calc(100% - 32px));background:#161b22;border:1px solid #30363d;border-radius:16px;padding:28px}.brand{font-size:30px;font-weight:850}.brand b{color:#ff7a1a}.muted{color:#8b949e}.state{display:inline-block;margin:12px 0;padding:6px 10px;border:1px solid #246b35;border-radius:999px;color:#9be9a8;background:#102719}code{background:#0d1117;border:1px solid #30363d;border-radius:5px;padding:2px 5px}</style></head><body><main class="card"><div class="brand">Forwext <b>Forum Platform</b></div><div class="state">Kurulum sağlıklı</div><p>Forwext geliştirme altyapısı ve veritabanı migration sistemi çalışıyor.</p><p class="muted">Kurulu sürüm: <code><?= $safeVersion ?></code>. Forum kullanıcı arayüzü ve kalan 1.0 roadmap modülleri geliştirme planına göre eklenmeye devam ediyor.</p></main></body></html>
