<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Faq\FaqArticle;
use Forwext\Core\Faq\FaqArticleView;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Routing\BasePath;

final class FaqHtml
{
    /**
     * @param list<FaqCategory> $categories
     * @param array<string,list<FaqArticle>> $articles
     */
    public static function index(
        array $categories,
        array $articles,
        BasePath $basePath,
        ?string $language,
        bool $authenticated,
    ): string {
        $articleCount = 0;
        $languages = [];
        foreach ($categories as $category) {
            $languages[$category->language] = true;
            $articleCount += count($articles[$category->key] ?? []);
        }

        $tabs = '<nav class="faq-tabs" aria-label="SSS dilleri">';
        $allClass = $language === null ? 'faq-tab is-active' : 'faq-tab';
        $allCurrent = $language === null ? ' aria-current="page"' : '';
        $tabs .= '<a class="' . $allClass . '" href="' . self::e($basePath->prepend('/faq')) . '"'
            . $allCurrent . '>Tümü</a>';
        foreach (array_keys($languages) as $lang) {
            $active = $language === $lang;
            $tabs .= '<a class="faq-tab' . ($active ? ' is-active' : '') . '" href="'
                . self::e($basePath->prepend('/faq?lang=' . rawurlencode($lang))) . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . self::e($lang) . '</a>';
        }
        $tabs .= '</nav>';

        $body = '<section class="faq-index discovery-page"><header class="surface-head faq-head"><div>'
            . '<span class="forum-eyebrow">YARDIM</span><h1>Sık Sorulan Sorular</h1>'
            . '<p>Kategoriye göre sık sorulan sorular ve çözümler.</p></div></header>'
            . '<section class="surface-panel faq-overview"><div class="faq-overview-stats">'
            . '<span><strong>' . count($categories) . '</strong> kategori</span>'
            . '<span><strong>' . $articleCount . '</strong> makale</span>'
            . ($language === null ? '' : '<span>Dil · <strong>' . self::e($language) . '</strong></span>')
            . '</div>' . $tabs . '</section>';

        if ($categories === []) {
            $body .= '<section class="surface-panel"><div class="surface-empty">'
                . '<strong>SSS içeriği bulunamadı.</strong>'
                . '<span>Bu görünürlük veya dil için yayımlanmış içerik yok.</span></div></section>';
        }

        foreach ($categories as $category) {
            $items = $articles[$category->key] ?? [];
            $body .= '<section class="surface-panel faq-category"><header class="faq-category-head"><div>'
                . '<h2>' . self::e($category->label) . '</h2>'
                . ($category->description === '' ? '' : '<p>' . self::e($category->description) . '</p>')
                . '</div><span>' . count($items) . ' soru</span></header>';

            if ($items === []) {
                $body .= '<div class="surface-empty faq-category-empty"><strong>Henüz soru yok.</strong>'
                    . '<span>Bu kategoride yayımlanmış bir SSS makalesi bulunmuyor.</span></div>';
            } else {
                foreach ($items as $article) {
                    $href = $basePath->prepend(
                        '/faq/' . rawurlencode($article->language) . '/' . rawurlencode($article->slug),
                    );
                    $body .= '<a class="faq-row" href="' . self::e($href) . '"><div>'
                        . '<span class="faq-row-language">' . self::e($article->language) . '</span>'
                        . '<strong>' . self::e($article->question) . '</strong>';
                    if ($article->tags !== []) {
                        $body .= '<span class="faq-row-tags">';
                        foreach (array_slice($article->tags, 0, 4) as $tag) {
                            $body .= '<small>#' . self::e($tag) . '</small>';
                        }
                        if (count($article->tags) > 4) {
                            $body .= '<small>+' . (count($article->tags) - 4) . '</small>';
                        }
                        $body .= '</span>';
                    }
                    $body .= '</div><span class="faq-row-arrow" aria-hidden="true">→</span></a>';
                }
            }
            $body .= '</section>';
        }
        $body .= '</section>';

        return ProfileHtml::page(
            $language === null ? 'Sık Sorulan Sorular' : 'SSS · ' . $language,
            $body,
            $basePath,
            authenticated:$authenticated,
        );
    }

    public static function article(
        FaqArticleView $view,
        BasePath $basePath,
        ?string $csrfToken,
        bool $authenticated,
        bool $voted = false,
    ): string {
        $article = $view->article;
        $ratio = $view->helpful->ratio();
        $analytics = $view->helpful->total() === 0
            ? 'Henüz değerlendirme yok.'
            : sprintf(
                '%d değerlendirme · %% %.0f faydalı',
                $view->helpful->total(),
                ($ratio ?? 0.0) * 100,
            );

        $body = '<article class="faq-article discovery-page"><header class="surface-head faq-article-head"><div>'
            . '<span class="forum-eyebrow">' . self::e($view->category->label) . ' · ' . self::e($article->language)
            . '</span><h1>' . self::e($article->question) . '</h1></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/faq')) . '">SSS’ye dön</a></header>'
            . '<div class="faq-article-grid"><section class="surface-panel faq-answer"><div class="about">'
            . nl2br(self::e($article->answer), false) . '</div>';

        if ($article->tags !== []) {
            $body .= '<div class="faq-article-tags" aria-label="Etiketler">';
            foreach ($article->tags as $tag) {
                $body .= '<span>#' . self::e($tag) . '</span>';
            }
            $body .= '</div>';
        }
        $body .= '</section><aside class="surface-panel faq-feedback-panel"><div class="faq-feedback-summary">'
            . '<span>Bu içerik yardımcı oldu mu?</span><strong>' . self::e($analytics) . '</strong></div>';

        if ($voted) {
            $body .= '<div class="notification-settings-notice" role="status">Değerlendirmeniz kaydedildi.</div>';
        }

        if ($authenticated && $csrfToken !== null) {
            $action = $basePath->prepend(
                '/faq/' . rawurlencode($article->language) . '/' . rawurlencode($article->slug),
            );
            $body .= '<form method="post" action="' . self::e($action) . '" class="faq-feedback-actions">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
                . '<button class="fx-btn fx-btn--primary" type="submit" name="helpful" value="1">Evet, faydalı</button>'
                . '<button class="fx-btn" type="submit" name="helpful" value="0">Hayır</button></form>';
        } else {
            $body .= '<p class="muted faq-feedback-login">Değerlendirme yapmak için oturum açın.</p>';
        }

        $body .= '</aside></div></article>';

        return ProfileHtml::page(
            $article->seoTitle ?? $article->question,
            $body,
            $basePath,
            authenticated:$authenticated,
        );
    }

    /**
     * @param list<FaqCategory> $categories
     * @param list<FaqArticle> $articles
     * @param array<string,\Forwext\Core\Faq\FaqHelpfulSummary> $helpful
     */
    public static function manage(
        array $categories,
        array $articles,
        array $helpful,
        BasePath $basePath,
        string $csrfToken,
        bool $updated,
        ?string $error = null,
    ): string {
        $action = self::e($basePath->prepend('/faq/manage'));
        $notice = $updated
            ? '<div class="notification-settings-notice" role="status">SSS içeriği güncellendi.</div>'
            : '';
        if ($error !== null) {
            $notice .= '<div class="auth-entry-error" role="alert">' . self::e($error) . '</div>';
        }

        $categoryOptions = '';
        foreach ($categories as $category) {
            $categoryOptions .= '<option value="' . self::e($category->key) . '">'
                . self::e($category->label . ' (' . $category->language . ')') . '</option>';
        }

        $visibility = '';
        foreach (FaqVisibility::cases() as $case) {
            $visibility .= '<option value="' . self::e($case->value) . '">' . self::e($case->value) . '</option>';
        }

        $body = '<section class="module-manage-page discovery-page"><header class="surface-head module-manage-head"><div>'
            . '<span class="forum-eyebrow">SSS YÖNETİMİ</span><h1>SSS Yönetimi</h1>'
            . '<p>Kategori, içerik, görünürlük, dil, sıralama, SEO ve import/export işlemlerini yönet.</p></div>'
            . '<div class="module-manage-head-actions">'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/faq/manage?export=1')) . '">JSON dışa aktar</a>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/faq/manage/support-drafts')) . '">Destek taslakları</a>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/faq')) . '">SSS’ye dön</a>'
            . '</div></header>' . $notice

            . '<details class="surface-panel module-manage-details" open><summary><strong>Kategori kaydet</strong>'
            . '<span>Yeni kategori oluştur veya mevcut yapılandırmayı güncelle.</span></summary>'
            . '<form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="category_save">'
            . '<label><span>Anahtar</span><input name="key" maxlength="64" required></label>'
            . '<label><span>Başlık</span><input name="label" maxlength="120" required></label>'
            . '<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="500"></textarea></label>'
            . '<label><span>Dil</span><input name="language" value="tr" maxlength="32" required></label>'
            . '<label><span>Görünürlük</span><select name="visibility">' . $visibility . '</select></label>'
            . '<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="65535" value="100"></label>'
            . '<label><input type="checkbox" name="active" value="1" checked> Aktif</label>'
            . '<div class="search-actions"><button type="submit">Kategoriyi kaydet</button></div></form></details>'

            . '<details class="surface-panel module-manage-details"><summary><strong>Makale kaydet</strong>'
            . '<span>SSS makalesi oluştur veya ID ile düzenle.</span></summary>'
            . '<form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="article_save">'
            . '<label><span>Makale ID (düzenleme için; boşsa yeni)</span><input name="article_id" maxlength="32"></label>'
            . '<label><span>Kategori</span><select name="category" required>' . $categoryOptions . '</select></label>'
            . '<label><span>Slug</span><input name="slug" maxlength="160" required></label>'
            . '<label class="search-wide"><span>Soru</span><input name="question" maxlength="300" required></label>'
            . '<label class="search-wide"><span>Cevap</span><textarea name="answer" maxlength="50000" rows="10" required></textarea></label>'
            . '<label><span>Etiketler</span><input name="tags" maxlength="1000" placeholder="virgülle"></label>'
            . '<label><span>Dil</span><input name="language" value="tr" maxlength="32" required></label>'
            . '<label><span>Görünürlük</span><select name="visibility">' . $visibility . '</select></label>'
            . '<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="65535" value="100"></label>'
            . '<label><span>SEO başlık</span><input name="seo_title" maxlength="200"></label>'
            . '<label class="search-wide"><span>SEO açıklama</span><textarea name="seo_description" maxlength="320"></textarea></label>'
            . '<label><input type="checkbox" name="active" value="1" checked> Aktif</label>'
            . '<div class="search-actions"><button type="submit">Makaleyi kaydet</button></div></form></details>'

            . '<details class="surface-panel module-manage-details"><summary><strong>JSON içe aktar</strong>'
            . '<span>Forwext FAQ JSON içeriğini içe aktar.</span></summary>'
            . '<form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="import">'
            . '<label class="search-wide"><span>Forwext FAQ JSON</span><textarea name="json" maxlength="5000000" rows="12" required></textarea></label>'
            . '<div class="search-actions"><button type="submit">İçe aktar</button></div></form></details>'

            . '<section class="surface-panel module-manage-section"><header><div><h2>Mevcut içerik</h2><p>'
            . count($categories) . ' kategori · ' . count($articles) . ' makale</p></div>'
            . '<span>' . count($articles) . '</span></header><div class="faq-manage-list">';

        if ($articles === []) {
            $body .= '<div class="surface-empty"><strong>Makale bulunmuyor.</strong>'
                . '<span>Yeni SSS makaleleri burada listelenecek.</span></div>';
        } else {
            foreach ($articles as $article) {
                $summary = $helpful[$article->articleId->value()] ?? null;
                $metric = $summary === null || $summary->total() === 0
                    ? '0 değerlendirme'
                    : $summary->total() . ' değerlendirme / %'
                        . number_format(($summary->ratio() ?? 0.0) * 100, 0) . ' faydalı';

                $body .= '<article class="faq-manage-row"><div><span>'
                    . self::e($article->language . ' · ' . $article->visibility->value) . '</span><strong>'
                    . self::e($article->question) . '</strong><small>' . self::e($metric)
                    . ' · ID ' . self::e($article->articleId->value()) . '</small></div></article>';
            }
        }

        $body .= '</div></section></section>';

        return ProfileHtml::page('SSS Yönetimi', $body, $basePath, authenticated:true);
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
