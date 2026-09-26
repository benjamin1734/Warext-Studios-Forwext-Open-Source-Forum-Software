<?php

declare(strict_types=1);

use Forwext\Core\Health\HealthStatus;
use Forwext\Core\Install\InstallationFailureReporter;
use Forwext\Core\Install\InstallationInput;
use Forwext\Core\Install\InstallationPreflight;
use Forwext\Core\Install\InstallationService;
use Forwext\Core\Module\FirstParty\FirstPartyModuleRegistry;
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
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");

$error = null;
$completed = false;
$report = null;
$health = null;
$installer = null;
$modules = [];
$preflightChecks = [[
    'key' => 'autoload',
    'label' => 'Hazır production vendor',
    'passed' => false,
    'required' => true,
    'detail' => 'vendor/autoload.php bulunamadı. GitHub Release içindeki full ZIP paketini kullanın.',
]];
$requirementsOk = false;

if (!is_file($autoload)) {
    $error = 'Kurulum paketi eksik: vendor/autoload.php bulunamadı. Kaynak kod ZIP’i yerine Forwext full kurulum ZIP’ini kullanın.';
} else {
    require $autoload;

    $preflight = new InstallationPreflight($root);
    $preflightChecks = $preflight->checks();
    $requirementsOk = $preflight->isReady();
    $installer = new InstallationService($root);
    $modules = FirstPartyModuleRegistry::withCoreDefaults()->all();

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
                    field('database_password', false),
                    siteName: field('site_name'),
                    siteDescription: field('site_description'),
                    siteLocale: field('site_locale'),
                    siteTimezone: field('site_timezone'),
                    adminUsername: field('admin_username'),
                    adminEmail: field('admin_email'),
                    adminPassword: field('admin_password', false),
                    mailDriver: field('mail_driver'),
                    mailFromAddress: field('mail_from_address'),
                    mailFromName: field('mail_from_name'),
                    smtpHost: field('smtp_host'),
                    smtpPort: (int) field('smtp_port'),
                    smtpEncryption: field('smtp_encryption'),
                    smtpUsername: field('smtp_username'),
                    smtpPassword: field('smtp_password', false),
                    enabledModules: moduleFields(),
                    themePreset: field('theme_preset'),
                );
                $report = $installer->install($input);
                $health = $installer->lastHealthReport();
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
$installBasePath = class_exists(RuntimeCanonicalUrlResolver::class)
    ? RuntimeCanonicalUrlResolver::scriptBasePath($scriptName)
    : '';
$defaultUrl = ($secure ? 'https://' : 'http://')
    . ($host !== '' ? $host : 'example.com')
    . $installBasePath;

$selectedModules = selectedModulesForForm($modules);
$cronCommand = 'php ' . escapeshellarg($root . '/bin/webhook-worker.php') . ' 25';

function field(string $name, bool $trim = true): string
{
    $value = $_POST[$name] ?? '';
    if (!is_string($value) || strlen($value) > 4096 || str_contains($value, "\0")) {
        throw new InvalidArgumentException('Kurulum alanlarından biri geçersiz.');
    }

    return $trim ? trim($value) : $value;
}

/** @return list<string> */
function moduleFields(): array
{
    $value = $_POST['enabled_modules'] ?? [];
    if (!is_array($value) || count($value) > 100) {
        throw new InvalidArgumentException('Modül seçimi geçersiz.');
    }

    $modules = [];
    foreach ($value as $module) {
        if (!is_string($module) || strlen($module) > 64 || str_contains($module, "\0")) {
            throw new InvalidArgumentException('Modül seçimi geçersiz.');
        }
        $modules[] = $module;
    }

    return $modules;
}

/** @param list<object> $definitions @return list<string> */
function selectedModulesForForm(array $definitions): array
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            return moduleFields();
        } catch (Throwable) {
            return [];
        }
    }

    $keys = [];
    foreach ($definitions as $definition) {
        if (isset($definition->key) && is_string($definition->key)) {
            $keys[] = $definition->key;
        }
    }

    return $keys;
}

function old(string $name, string $fallback = ''): string
{
    $value = $_POST[$name] ?? $fallback;
    return is_string($value) ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
}

function selected(string $name, string $value, string $fallback = ''): string
{
    $current = $_POST[$name] ?? $fallback;
    return is_string($current) && hash_equals($current, $value) ? ' selected' : '';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Forwext Kurulum</title>
<style>
:root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--panel2:#11161d;--line:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#ff7a1a;--ok:#3fb950;--bad:#f85149;--warn:#d29922}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif}.wrap{width:min(980px,calc(100% - 32px));margin:42px auto 64px}.brand{font-size:30px;font-weight:850;letter-spacing:-.5px}.brand span{color:var(--accent)}.sub{color:var(--muted);margin:4px 0 28px}.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:24px;margin-bottom:18px}.card h2{font-size:18px;margin:0 0 7px}.card>.hint{margin:0 0 18px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px}.full{grid-column:1/-1}.req{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px 16px}.req small{display:block;color:var(--muted)}.ok{color:var(--ok)}.bad{color:var(--bad)}label{display:block;font-weight:650;margin:13px 0 6px}input,select,textarea{width:100%;padding:11px 12px;border:1px solid var(--line);border-radius:8px;background:#0d1117;color:var(--text);font:inherit}textarea{min-height:76px;resize:vertical}.check-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.check{display:flex;gap:10px;align-items:flex-start;background:var(--panel2);border:1px solid var(--line);border-radius:9px;padding:11px}.check input{width:auto;margin-top:4px}.check strong{display:block}.check span{display:block;color:var(--muted);font-size:12px}.actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap}button{margin-top:20px;border:0;border-radius:9px;padding:12px 18px;background:var(--accent);color:white;font-weight:800;cursor:pointer}button:disabled{opacity:.45;cursor:not-allowed}.alert{border:1px solid #6e2b28;background:#2b1515;color:#ffb4ae;border-radius:10px;padding:13px 15px;margin-bottom:18px}.success{border-color:#246b35;background:#102719;color:#b7f7c3}.meta,.hint{font-size:13px;color:var(--muted)}code{background:#0d1117;border:1px solid var(--line);padding:2px 5px;border-radius:5px;word-break:break-all}.cron{display:block;padding:12px;margin:10px 0;background:#0d1117;border:1px solid var(--line);border-radius:8px;color:#d2e7ff}.pill{display:inline-block;border:1px solid var(--line);padding:3px 8px;border-radius:999px;font-size:12px;color:var(--muted)}a{color:#9be9a8}@media(max-width:720px){.grid,.check-grid{grid-template-columns:1fr}.full{grid-column:auto}.wrap{margin-top:24px}.card{padding:18px}}</style>
</head>
<body><main class="wrap">
<div class="brand">Forwext <span>Installer</span></div>
<p class="sub">Warext Studios · PHP 8.4+ · cPanel-first web kurulumu · Composer/Node/SSH gerekmez</p>

<?php if ($error !== null): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>

<?php if ($completed): ?>
<div class="card success">
<h2>Kurulum tamamlandı</h2>
<p>Veritabanı migration’ları, site ayarları, yönetici hesabı, modül/tema başlangıç durumu ve kurulum kilidi tamamlandı.</p>
<?php if ($report !== null): ?><p class="meta">Migration batch: <?= (int) $report->batch ?> · Uygulanan: <?= count($report->applied) ?> · Atlanan: <?= count($report->skipped) ?></p><?php endif; ?>
<?php if ($health !== null): ?><p class="meta">Post-install health: <strong class="<?= $health->status === HealthStatus::Healthy ? 'ok' : '' ?>"><?= h($health->status->value) ?></strong></p><?php endif; ?>
<p><a href="./">Forwext’i aç →</a></p>
</div>
<div class="card">
<h2>cPanel Cron Jobs</h2>
<p class="hint">Terminal açmanız gerekmez. cPanel → Cron Jobs üzerinden aşağıdaki komutu dakikada bir çalışacak şekilde ekleyin. Hostinginizde CLI PHP komutu farklıysa yalnızca baştaki <code>php</code> bölümünü sağlayıcınızın PHP 8.4+ yolu ile değiştirin.</p>
<span class="pill">Önerilen sıklık: her dakika</span>
<code class="cron"><?= h($cronCommand) ?></code>
<p class="meta">Bu görev webhook teslimat kuyruğunu küçük ve güvenli batch’ler halinde işler; web kurulumu cron komutunu çalıştırmayı gerektirmez.</p>
</div>
<?php else: ?>

<div class="card"><h2>1. Sunucu ön kontrolü</h2><p class="hint">Full ZIP kendi production bağımlılıklarını içerir; sunucuda Composer veya Node kurulumu gerekmez.</p><div class="req">
<?php foreach ($preflightChecks as $check): ?>
<div><strong><?= h((string) $check['label']) ?></strong><small><?= h((string) $check['detail']) ?></small></div>
<strong class="<?= $check['passed'] ? 'ok' : 'bad' ?>"><?= $check['passed'] ? 'Hazır' : 'Eksik' ?></strong>
<?php endforeach; ?>
</div></div>

<form method="post" autocomplete="off">
<input type="hidden" name="_token" value="<?= h((string) $_SESSION['install_csrf']) ?>">

<div class="card">
<h2>2. Site bilgileri</h2>
<p class="hint">Forumun temel kimliği ve varsayılan bölgesel ayarları.</p>
<div class="grid">
<div><label for="site_name">Site adı</label><input id="site_name" name="site_name" required maxlength="120" value="<?= old('site_name', 'Forwext') ?>"></div>
<div><label for="canonical_url">Site URL</label><input id="canonical_url" name="canonical_url" type="url" required value="<?= old('canonical_url', $defaultUrl) ?>"></div>
<div class="full"><label for="site_description">Kısa açıklama</label><textarea id="site_description" name="site_description" maxlength="500"><?= old('site_description') ?></textarea></div>
<div><label for="site_locale">Varsayılan dil/locale</label><input id="site_locale" name="site_locale" required value="<?= old('site_locale', 'tr') ?>"></div>
<div><label for="site_timezone">Saat dilimi</label><input id="site_timezone" name="site_timezone" required value="<?= old('site_timezone', 'UTC') ?>"><div class="meta">Örnek: Europe/Istanbul, UTC</div></div>
</div>
</div>

<div class="card">
<h2>3. MySQL / MariaDB</h2>
<p class="hint">cPanel → MySQL Databases üzerinden oluşturduğunuz boş veritabanı ve kullanıcı bilgilerini girin.</p>
<div class="grid">
<div><label for="database_host">Sunucu</label><input id="database_host" name="database_host" required maxlength="191" value="<?= old('database_host', 'localhost') ?>"></div>
<div><label for="database_port">Port</label><input id="database_port" name="database_port" type="number" min="1" max="65535" required value="<?= old('database_port', '3306') ?>"></div>
<div><label for="database_name">Veritabanı adı</label><input id="database_name" name="database_name" required maxlength="191" value="<?= old('database_name') ?>"></div>
<div><label for="database_username">Veritabanı kullanıcısı</label><input id="database_username" name="database_username" required maxlength="191" value="<?= old('database_username') ?>"></div>
<div class="full"><label for="database_password">Veritabanı şifresi</label><input id="database_password" name="database_password" type="password" maxlength="4096" value="" autocomplete="new-password"><div class="meta">Düz metin config dosyasına yazılmaz; Forwext encrypted secret store içinde saklanır.</div></div>
</div>
</div>

<div class="card">
<h2>4. İlk yönetici</h2>
<p class="hint">Bu hesap aktif olarak oluşturulur ve migration’ların ürettiği güncel <code>administrator</code> yetki şablonu uygulanır.</p>
<div class="grid">
<div><label for="admin_username">Kullanıcı adı</label><input id="admin_username" name="admin_username" required minlength="3" maxlength="32" value="<?= old('admin_username', 'administrator') ?>"></div>
<div><label for="admin_email">E-posta</label><input id="admin_email" name="admin_email" type="email" required maxlength="254" value="<?= old('admin_email') ?>"></div>
<div class="full"><label for="admin_password">Yönetici şifresi</label><input id="admin_password" name="admin_password" type="password" required minlength="12" maxlength="1024" autocomplete="new-password"><div class="meta">En az 12 karakter. Şifre modern PHP password hashing policy ile saklanır.</div></div>
</div>
</div>

<div class="card">
<h2>5. E-posta</h2>
<p class="hint">İsterseniz kurulumu e-posta kapalı tamamlayıp daha sonra ACP’den yapılandırabilirsiniz. SMTP şifresi de encrypted secret store’a yazılır.</p>
<div class="grid">
<div><label for="mail_driver">Mail sürücüsü</label><select id="mail_driver" name="mail_driver"><option value="disabled"<?= selected('mail_driver','disabled','disabled') ?>>Şimdilik kapalı</option><option value="smtp"<?= selected('mail_driver','smtp') ?>>SMTP</option></select></div>
<div><label for="mail_from_name">Gönderen adı</label><input id="mail_from_name" name="mail_from_name" maxlength="120" value="<?= old('mail_from_name', 'Forwext') ?>"></div>
<div><label for="mail_from_address">Gönderen e-posta</label><input id="mail_from_address" name="mail_from_address" type="email" maxlength="254" value="<?= old('mail_from_address') ?>"></div>
<div><label for="smtp_host">SMTP sunucusu</label><input id="smtp_host" name="smtp_host" maxlength="255" value="<?= old('smtp_host') ?>"></div>
<div><label for="smtp_port">SMTP portu</label><input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= old('smtp_port', '587') ?>"></div>
<div><label for="smtp_encryption">Şifreleme</label><select id="smtp_encryption" name="smtp_encryption"><option value="starttls"<?= selected('smtp_encryption','starttls','starttls') ?>>STARTTLS</option><option value="tls"<?= selected('smtp_encryption','tls') ?>>TLS</option><option value="none"<?= selected('smtp_encryption','none') ?>>Yok</option></select></div>
<div><label for="smtp_username">SMTP kullanıcısı</label><input id="smtp_username" name="smtp_username" maxlength="255" value="<?= old('smtp_username') ?>"></div>
<div><label for="smtp_password">SMTP şifresi</label><input id="smtp_password" name="smtp_password" type="password" maxlength="4096" autocomplete="new-password"></div>
</div>
</div>

<div class="card">
<h2>6. İlk modüller</h2>
<p class="hint">İlk kurulum durumunu seçin. Bağımlı bir modülü açık bırakırken ihtiyaç duyduğu modülü kapatırsanız installer işlemi güvenli biçimde reddeder.</p>
<div class="check-grid">
<?php foreach ($modules as $module): ?>
<label class="check"><input type="checkbox" name="enabled_modules[]" value="<?= h($module->key) ?>"<?= in_array($module->key, $selectedModules, true) ? ' checked' : '' ?>><span><strong><?= h($module->name) ?></strong><?= h($module->description) ?></span></label>
<?php endforeach; ?>
</div>
</div>

<div class="card">
<h2>7. Tema başlangıcı</h2>
<p class="hint">Seçim gerçek theme revision sisteminde ilk published tema kaydını oluşturur.</p>
<label for="theme_preset">Başlangıç teması</label>
<select id="theme_preset" name="theme_preset">
<option value="balanced"<?= selected('theme_preset','balanced','balanced') ?>>Dengeli — güvenli varsayılan</option>
<option value="compact"<?= selected('theme_preset','compact') ?>>Kompakt — daha yoğun görünüm</option>
<option value="showcase"<?= selected('theme_preset','showcase') ?>>Vitrin — daha ferah sunum</option>
</select>
</div>

<div class="card">
<h2>8. Kurulum ve doğrulama</h2>
<p class="hint">Installer migration’ları uygular, ilk yöneticiyi/yetkileri kurar, modül ve temayı hazırlar, ardından runtime + DB + yazılabilir dizin health kontrollerini geçirir. Tümü başarılı olmadan kalıcı install lock yazılmaz.</p>
<div class="actions"><button type="submit" <?= $requirementsOk ? '' : 'disabled' ?>>Forwext’i Kur</button><span class="meta">Terminal komutu çalıştırmanız gerekmez.</span></div>
</div>
</form>
<?php endif; ?>
</main></body></html>
