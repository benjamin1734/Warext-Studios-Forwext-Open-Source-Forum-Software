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

$basePath = new BasePath('/public');

$fixtures = [
    'guest' => ProfileHtml::page('Tarayıcı Testi · Misafir', $forumContent, $basePath, authenticated: false),
    'member' => ProfileHtml::page('Tarayıcı Testi · Üye', $forumContent, $basePath, authenticated: true),
];

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
