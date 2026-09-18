<?php

declare(strict_types=1);

use Forwext\Core\Install\InstallationFailureReporter;
use Forwext\Core\Install\InstallationInput;
use Forwext\Core\Install\InstallationService;
use Forwext\Core\Routing\RuntimeCanonicalUrlResolver;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (PHP_VERSION_ID < 80400) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="tr"><meta charset="utf-8"><title>Forwext kurulum gereksinimi</title>';
    echo '<body style="font:16px/1.55 system-ui,sans-serif;max-width:760px;margin:48px auto;padding:0 20px">';
    echo '<h1>PHP 8.4 veya üzeri gerekli</h1>';
    echo '<p>Kurulum başlatılamadı. Sunucuda çalışan PHP sürümü: <strong>'
        . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
    echo '<p>cPanel kullanıyorsanız MultiPHP Manager üzerinden bu alan adını PHP 8.4 veya daha yeni bir sürüme alın.</p>';
    echo '</body></html>';
    exit;
}

$secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$requirements = [
    'PHP 8.4+' => version_compare(PHP_VERSION, '8.4.0', '>='),
    'OpenSSL' => extension_loaded('openssl'),
    'PDO' => extension_loaded('pdo'),
    'PDO MySQL' => extension_loaded('pdo_mysql'),
    'JSON' => extension_loaded('json'),
    'config/ yazılabilir' => is_dir($root . '/config') && is_writable($root . '/config'),
    'storage/ yazılabilir' => is_dir($root . '/storage') && is_writable($root . '/storage'),
    'vendor/autoload.php mevcut' => is_file($autoload),
];
$requirementsOk = !in_array(false, $requirements, true);
$error = null;
$completed = false;
$report = null;

if (!is_file($autoload)) {
    $error = 'Kurulum paketi eksik: vendor/autoload.php bulunamadı. Kaynak kod ZIP’i yerine Forwext kurulum ZIP’ini kullanın.';
} else {
    require $autoload;
    $installer = new InstallationService($root);

    if ($installer->isInstalled()) {
        $completed = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submitted = isset($_POST['_token']) && is_string($_POST['_token']) ? $_POST['_token'] : '';
        $sessionToken = isset($_SESSION['install_csrf']) && is_string($_SESSION['install_csrf'])
            ? $_SESSION['install_csrf']
            : '';

        if ($sessionToken === '' || $submitted === '' || !hash_equals($sessionToken, $submitted)) {
            $error = 'Kurulum oturumu doğrulanamadı. Sayfayı yenileyip tekrar deneyin.';
        } elseif (!$requirementsOk) {
            $error = 'Sunucu gereksinimleri tamamlanmadan kurulum başlatılamaz.';
        } else {
            try {
                $input = new InstallationInput(
                    field('canonical_url'),
                    field('database_host'),
                    (int) field('database_port'),
                    field('database_name'),
                    field('database_username'),
                    field('database_password', false)
                );
                $report = $installer->install($input);
                unset($_SESSION['install_csrf']);
                session_regenerate_id(true);
                $completed = true;
            } catch (Throwable $exception) {
                $error = (new InstallationFailureReporter(
                    $root . '/storage/logs/install.log',
                ))->report($exception);
            }
        }
    }
}

if (!isset($_SESSION['install_csrf']) || !is_string($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])
    ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'])
    : '';
$scriptName = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME'])
    ? $_SERVER['SCRIPT_NAME']
    : null;
$installBasePath = RuntimeCanonicalUrlResolver::scriptBasePath($scriptName);
$defaultUrl = ($secure ? 'https://' : 'http://')
    . ($host !== '' ? $host : 'example.com')
    . $installBasePath;

function field(string $name, bool $trim = true): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || strlen($value) > 4096 || str_contains($value, "\0")) {
        throw new InvalidArgumentException('Kurulum alanlarından biri geçersiz.');
    }

    return $trim ? trim($value) : $value;
}

function old(string $name, string $fallback = ''): string
{
    $value = $_POST[$name] ?? $fallback;
    return is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forwext Kurulum</title>
<style>
:root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--line:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#ff7a1a;--ok:#3fb950;--bad:#f85149}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{width:min(860px,calc(100% - 32px));margin:48px auto}.brand{font-size:29px;font-weight:800}.brand span{color:var(--accent)}.sub{color:var(--muted);margin:4px 0 28px}.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:24px;margin-bottom:18px}h2{font-size:18px;margin:0 0 16px}.req{display:grid;grid-template-columns:1fr auto;gap:9px 16px}.ok{color:var(--ok)}.bad{color:var(--bad)}label{display:block;font-weight:650;margin:14px 0 6px}input{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:8px;background:#0d1117;color:var(--text);font:inherit}button{margin-top:20px;border:0;border-radius:9px;padding:12px 18px;background:var(--accent);color:white;font-weight:800;cursor:pointer}button:disabled{opacity:.45;cursor:not-allowed}.alert{border:1px solid #6e2b28;background:#2b1515;color:#ffb4ae;border-radius:10px;padding:13px 15px;margin-bottom:18px}.success{border-color:#246b35;background:#102719;color:#9be9a8}.meta{font-size:13px;color:var(--muted)}code{background:#0d1117;border:1px solid var(--line);padding:2px 5px;border-radius:5px}</style>
</head>
<body><main class="wrap">
<div class="brand">Forwext <span>Installer</span></div>
<p class="sub">Warext Studios · PHP 8.4+ · cPanel uyumlu geliştirme kurulumu</p>

<?php if ($error !== null): ?><div class="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
<?php if ($completed): ?>
<div class="card success">
<h2>Kurulum tamamlandı</h2>
<p>Veritabanı migration’ları uygulandı, site konfigürasyonu oluşturuldu ve kurulum kilitlendi.</p>
<?php if ($report !== null): ?><p class="meta">Migration batch: <?= (int) $report->batch ?> · Uygulanan: <?= count($report->applied) ?> · Atlanan: <?= count($report->skipped) ?></p><?php endif; ?>
<p><a href="./" style="color:#9be9a8">Forwext durum sayfasına git →</a></p>
</div>
<?php else: ?>
<div class="card"><h2>1. Sunucu kontrolü</h2><div class="req">
<?php foreach ($requirements as $label => $passed): ?>
<span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong class="<?= $passed ? 'ok' : 'bad' ?>"><?= $passed ? 'Hazır' : 'Eksik' ?></strong>
<?php endforeach; ?>
</div></div>

<form method="post" autocomplete="off" class="card">
<h2>2. Site ve veritabanı</h2>
<input type="hidden" name="_token" value="<?= htmlspecialchars((string) $_SESSION['install_csrf'], ENT_QUOTES, 'UTF-8') ?>">
<label for="canonical_url">Site URL</label><input id="canonical_url" name="canonical_url" type="url" required value="<?= old('canonical_url', $defaultUrl) ?>">
<label for="database_host">MySQL sunucusu</label><input id="database_host" name="database_host" required value="<?= old('database_host', 'localhost') ?>">
<label for="database_port">MySQL portu</label><input id="database_port" name="database_port" type="number" min="1" max="65535" required value="<?= old('database_port', '3306') ?>">
<label for="database_name">Veritabanı adı</label><input id="database_name" name="database_name" required value="<?= old('database_name') ?>">
<label for="database_username">Veritabanı kullanıcısı</label><input id="database_username" name="database_username" required value="<?= old('database_username') ?>">
<label for="database_password">Veritabanı şifresi</label><input id="database_password" name="database_password" type="password" value="" autocomplete="new-password">
<p class="meta">Şifre düz metin config dosyasına yazılmaz; Forwext encrypted secret store içinde saklanır.</p>
<button type="submit" <?= $requirementsOk ? '' : 'disabled' ?>>Kurulumu Başlat</button>
</form>
<?php endif; ?>
<div class="meta">Bu paket mevcut geliştirme sürümünü kurar. Tam forum arayüzü ve kalan roadmap modülleri henüz 1.0 tamamlanana kadar aşamalı olarak eklenecektir.</div>
</main></body></html>
