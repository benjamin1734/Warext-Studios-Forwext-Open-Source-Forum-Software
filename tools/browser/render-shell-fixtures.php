<?php

declare(strict_types=1);

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Ui\Navigation\NavigationAudience;
use Forwext\Core\Ui\Navigation\NavigationItem;
use Forwext\Core\Ui\Navigation\NavigationRegistry;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$target = $root . '/build/browser-fixtures';
if (!is_dir($target) && !mkdir($target, 0777, true) && !is_dir($target)) {
    throw new RuntimeException('Browser fixture directory could not be created.');
}

$forumContent = <<<'HTML'
<section class="forum-home-layout" data-browser-fixture="forum">
  <div class="forum-home-main">
    <section class="forum-hero">
      <div>
        <span class="forum-eyebrow">Tarayıcı regresyon fixture</span>
        <h1>Forwext topluluk görünümü</h1>
        <p>Uzun içerik, navigasyon, form denetimleri ve konu görünümü farklı ekran genişliklerinde taşma oluşturmamalıdır.</p>
      </div>
      <div class="forum-hero-actions">
        <a class="fx-btn fx-btn--primary" href="#fixture-form">Yeni konu</a>
        <a class="fx-btn" href="#fixture-thread">Son konular</a>
      </div>
    </section>

    <section class="forum-category" aria-labelledby="fixture-category-title">
      <header class="forum-category-head">
        <div><h2 id="fixture-category-title">Genel forumlar</h2><p>Paylaşım ve topluluk alanı</p></div>
        <span>2 forum</span>
      </header>
      <div class="forum-node-list">
        <article class="forum-node">
          <a class="forum-node-icon" href="#fixture-thread" aria-label="Genel sohbet">G</a>
          <div class="forum-node-main">
            <h3><a href="#fixture-thread">Genel Sohbet</a></h3>
            <p>Topluluğun günlük sohbet ve paylaşım alanı.</p>
          </div>
          <div class="forum-node-counts"><div><strong>128</strong><span>Konu</span></div><div><strong>942</strong><span>Mesaj</span></div></div>
          <div class="forum-node-last"><a class="forum-last-title" href="#fixture-thread">Responsive ve erişilebilir forum arayüzü</a><span>forwext-test · şimdi</span></div>
        </article>
      </div>
    </section>

    <section id="fixture-thread" class="thread-post-list" aria-label="Örnek konu">
      <article class="thread-post">
        <aside class="thread-post-author">
          <div class="thread-post-avatar" aria-hidden="true">F</div>
          <a class="thread-post-author-name" href="#fixture-profile">forwext-test</a>
          <span class="muted">Üye</span>
        </aside>
        <div class="thread-post-body">
          <header><span>#1</span><a href="#fixture-thread">Kalıcı bağlantı</a></header>
          <div class="thread-post-content">
            <p>Bu örnek ileti içinde çok uzun bir bağlantı benzeri metin de bulunur: https://example.invalid/forwext/very-long-segment-that-must-wrap-without-causing-horizontal-page-overflow-or-breaking-the-mobile-layout</p>
          </div>
          <footer>
            <span class="muted">Bugün</span>
            <div class="thread-post-interactions">
              <details data-browser-post-menu>
                <summary class="fx-btn">Tepki</summary>
                <div class="thread-reaction-popover">
                  <button class="thread-reaction-option" type="button"><span aria-hidden="true">👍</span> Beğen</button>
                </div>
              </details>
              <a class="fx-btn" href="#fixture-form">Alıntıla</a>
            </div>
          </footer>
        </div>
      </article>
    </section>

    <form id="fixture-form" class="search-form surface-panel" action="#" method="get">
      <label class="search-wide"><span>Konu başlığı</span><input name="q" value="Responsive test" autocomplete="off"></label>
      <label><span>Tür</span><select name="type"><option>Forum</option><option>Üye</option></select></label>
      <label><span>Not</span><textarea name="note" rows="3">Klavye ile erişilebilir form.</textarea></label>
      <div class="search-actions"><button class="fx-btn fx-btn--primary" type="submit">Gönder</button></div>
    </form>
  </div>

  <aside class="forum-home-side" aria-label="Örnek kenar çubuğu">
    <section class="card forum-side-card"><h2>Forum istatistikleri</h2><div class="forum-mini-stats"><div><strong>42</strong><span>Üye</span></div><div><strong>128</strong><span>Konu</span></div><div><strong>942</strong><span>Mesaj</span></div></div></section>
  </aside>
</section>
HTML;

$moderationContent = <<<'HTML'
<section class="staff-dashboard" data-browser-fixture="moderator">
  <header class="surface-head staff-dashboard-head">
    <div><span class="surface-eyebrow">Moderasyon</span><h1>Moderasyon çalışma alanı</h1><p>Yetkili görünümü tablo, kuyruk ve aksiyon yoğunluğunu test eder.</p></div>
    <a class="fx-btn" href="#fixture-queue">Onay kuyruğu</a>
  </header>
  <div class="staff-dashboard-stats">
    <div class="staff-dashboard-stat"><strong>7</strong><span>Bekleyen rapor</span></div>
    <div class="staff-dashboard-stat"><strong>3</strong><span>Onay bekleyen</span></div>
    <div class="staff-dashboard-stat"><strong>1</strong><span>Açık görev</span></div>
  </div>
  <section id="fixture-queue" class="surface-panel staff-panel">
    <header><h2>İş kuyruğu</h2><span>3 kayıt</span></header>
    <div class="staff-queue-list">
      <a class="staff-queue-row" href="#fixture-review"><div class="staff-queue-main"><span class="staff-queue-meta">Rapor</span><strong>Örnek moderasyon kaydı</strong><p>Uzun açıklamalar küçük ekranlarda satır kırmalıdır.</p></div><span class="fx-btn">İncele</span></a>
    </div>
  </section>
  <section id="fixture-review" class="surface-panel staff-panel">
    <header><h2>Denetim tablosu</h2><span>Tarayıcı fixture</span></header>
    <div class="staff-table-wrap">
      <table><thead><tr><th>Kayıt</th><th>Kullanıcı</th><th>Durum</th><th>Zaman</th></tr></thead><tbody><tr><td>#1001</td><td>forwext-moderator</td><td>İncelemede</td><td>Şimdi</td></tr></tbody></table>
    </div>
  </section>
</section>
HTML;

$adminContent = <<<'HTML'
<section class="acp-dashboard" data-browser-fixture="admin">
  <nav class="acp-breadcrumbs" aria-label="Yönetim yolu">
    <ol><li><a href="#fixture-admin">Admin</a></li><li>Dashboard</li></ol>
  </nav>

  <aside class="acp-ux-guide" aria-label="Güvenli yönetim rehberi">
    <div><strong>Bu ekranda güvenli çalışma</strong><p>Kompleks ayarları önce bul, doğrula, sonra uygula.</p></div>
    <div class="acp-ux-grid">
      <div class="acp-ux-item"><strong>Amaç</strong><span>Yetkili yönetim alanlarını tek merkezden bul.</span></div>
      <div class="acp-ux-item"><strong>Güvenli varsayılan</strong><span>Salt-okunur özetlerden başlayıp gerekli ekrana ilerle.</span></div>
      <div class="acp-ux-item"><strong>Önizleme / doğrulama</strong><span>Değişiklikten önce etki alanını kontrol et.</span></div>
      <div class="acp-ux-item"><strong>Geri dönüş</strong><span>Desteklenen işlemlerde reset veya geri alma yolunu kullan.</span></div>
    </div>
  </aside>

  <header id="fixture-admin" class="acp-hero">
    <div><h1>Administration</h1><p class="acp-muted">ACP responsive, odak ve taşma regresyon fixture'ı.</p></div>
    <form id="fixture-form" class="acp-search" action="#" method="get">
      <label class="sr-only" for="admin-search">Yönetim alanlarında ara</label>
      <input id="admin-search" name="q" value="kullanıcı" autocomplete="off">
      <button type="button">Ara</button>
    </form>
  </header>

  <section class="acp-panel">
    <div class="acp-heading"><div><h2>Yönetim alanları</h2><p class="acp-muted">Kartlar dar ekranda tek kolona düşmelidir.</p></div><span class="acp-count">3</span></div>
    <div class="acp-grid">
      <article class="acp-card"><h3>Kullanıcılar</h3><p>Hesap, rol ve erişim yönetimi.</p><small>users.manage</small><div class="acp-actions"><a class="acp-button primary" href="#fixture-queue">Aç</a></div></article>
      <article class="acp-card"><h3>Forumlar</h3><p>Node, alan ve görünürlük ayarları.</p><small>forum.manage</small><div class="acp-actions"><a class="acp-button" href="#fixture-queue">Aç</a></div></article>
      <article class="acp-card"><h3>Sistem</h3><p>Uzun yönetim açıklamaları küçük ekranlarda yatay taşma üretmemelidir.</p><small>system.operations.manage</small><div class="acp-actions"><a class="acp-button" href="#fixture-queue">Aç</a></div></article>
    </div>
  </section>

  <section id="fixture-queue" class="acp-panel">
    <div class="acp-heading"><div><h2>İşlem gerekenler</h2><p class="acp-muted">Yoğun ACP kart düzeni.</p></div></div>
    <div class="acp-queue-grid">
      <article class="acp-queue"><div class="acp-queue-number">7</div><div><h3>Bekleyen rapor</h3><p>Yetkili kullanıcıların incelemesini bekleyen kayıtlar.</p><a class="acp-button" href="#fixture-admin">İncele</a></div></article>
      <article class="acp-queue"><div class="acp-queue-number">2</div><div><h3>Bakım görevi</h3><p>Planlanmış sistem görevi özeti.</p><a class="acp-button" href="#fixture-admin">İncele</a></div></article>
    </div>
  </section>

  <section class="mod-panel" data-browser-acp-module>
    <h2>First-party Module Manager</h2>
    <form class="mod-filter" action="#" method="get">
      <label>Modüllerde ara<input name="module_q" value="marketplace"></label>
      <label>Durum<select name="state"><option>Aktif</option></select></label>
      <button class="mod-button primary" type="button">Filtrele</button>
      <a class="mod-button" href="#fixture-admin">Sıfırla</a>
    </form>
    <div class="mod-shell">
      <aside class="mod-sidebar">
        <a class="mod-list-item is-selected" href="#fixture-module-detail"><span><strong>Marketplace</strong><small>marketplace</small></span><span class="mod-state state-enabled">Aktif</span></a>
        <a class="mod-list-item" href="#fixture-module-detail"><span><strong>Portfolio</strong><small>portfolio</small></span><span class="mod-state">Kapalı</span></a>
      </aside>
      <main id="fixture-module-detail" class="mod-detail">
        <section class="mod-panel">
          <div class="mod-head"><div><h1>Marketplace</h1><p class="mod-muted">Scoped ayarlar ve lifecycle yönetimi.</p></div><span class="mod-state state-enabled">Aktif</span></div>
          <div class="mod-setting"><div><strong>Liste sayfa boyutu</strong><p class="mod-muted">Effective değer: 24</p></div><div class="mod-setting-actions"><form><label class="mod-field">Değer<input value="24"></label><button class="mod-button" type="button">Kaydet</button></form></div></div>
        </section>
      </main>
    </div>
  </section>

  <section class="card builder-admin" data-browser-acp-builder>
    <div class="builder-toolbar"><button type="button">Taslak oluştur</button><a href="#fixture-admin">Dışa aktar</a></div>
    <div class="builder-grid">
      <aside class="builder-palette"><button class="builder-widget-add" type="button"><strong>Forum istatistikleri</strong><small>sidebar.primary</small></button></aside>
      <div class="builder-preview-shell"><div class="builder-preview" data-device="desktop"><section class="builder-slot"><header><strong>sidebar.primary</strong><span>1 widget</span></header><div class="builder-dropzone"><article class="builder-placement"><div class="builder-placement-head"><strong>Forum istatistikleri</strong><span>Aktif</span></div></article></div></section></div></div>
    </div>
  </section>

  <section class="theme-admin" data-browser-acp-theme>
    <aside class="theme-sidebar"><a class="theme-list-item" href="#fixture-theme"><strong>Default</strong><small>Base theme</small></a></aside>
    <section id="fixture-theme" class="theme-editor">
      <form><label>Tema adı<input value="Forwext Dark"></label><label>Özel CSS<textarea rows="4">.example { display: block; }</textarea></label><div class="theme-actions"><button type="button">Taslağı kaydet</button><button type="button">Önizle</button></div></form>
    </section>
  </section>
</section>
HTML;

$basePath = new BasePath('/public');

$fixtures = [
    'guest' => ProfileHtml::page('Tarayıcı Testi · Misafir', $forumContent, $basePath, authenticated: false),
    'member' => ProfileHtml::page('Tarayıcı Testi · Üye', $forumContent, $basePath, authenticated: true),
];

$adminHeadAssets = '<link rel="stylesheet" href="'
    . ProfileHtml::escape($basePath->prepend('/assets/admin.css')) . '">';
$fixtures['admin'] = ProfileHtml::page(
    'Tarayıcı Testi · Administration',
    $adminContent,
    $basePath,
    authenticated: true,
    headAssets: $adminHeadAssets,
);

$moderatorNavigation = NavigationRegistry::withCoreDefaults();
$moderatorNavigation->register(new NavigationItem(
    'moderation',
    'Moderasyon',
    '/moderation',
    275,
    NavigationAudience::Member,
));
$fixtures['moderator'] = ProfileHtml::page(
    'Tarayıcı Testi · Moderatör',
    $moderationContent,
    $basePath,
    navigation: $moderatorNavigation,
    authenticated: true,
);

foreach ($fixtures as $name => $html) {
    $html = preg_replace_callback(
        '/<script src="([^"]+)" defer><\/script>/',
        static function (array $match): string {
            return str_ends_with($match[1], '/assets/mobile-nav.js') ? $match[0] : '';
        },
        $html,
    );
    if (!is_string($html)) {
        throw new RuntimeException('Browser fixture script isolation failed.');
    }

    $written = file_put_contents($target . '/' . $name . '.html', $html);
    if ($written === false) {
        throw new RuntimeException('Could not write browser fixture: ' . $name);
    }
}

fwrite(STDOUT, sprintf("Rendered %d browser fixtures to %s\n", count($fixtures), $target));
