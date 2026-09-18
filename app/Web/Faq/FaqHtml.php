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
        $tabs = '<div class="tabs"><a href="' . self::e($basePath->prepend('/faq')) . '">Tümü</a>';
        $languages = [];
        foreach ($categories as $category) {
            $languages[$category->language] = true;
        }
        foreach (array_keys($languages) as $lang) {
            $tabs .= '<a href="' . self::e($basePath->prepend('/faq?lang=' . rawurlencode($lang))) . '">'
                . self::e($lang) . '</a>';
        }
        $tabs .= '</div>';

        $body = '<section class="card"><h1>Sık Sorulan Sorular</h1>'
            . '<p class="muted">Kategoriye göre sık sorulan sorular ve çözümler.</p>' . $tabs;
        if ($categories === []) {
            $body .= '<div class="empty">Bu görünürlük ve dil için SSS içeriği bulunmuyor.</div>';
        }
        foreach ($categories as $category) {
            $body .= '<section class="section"><h2>' . self::e($category->label) . '</h2>';
            if ($category->description !== '') {
                $body .= '<p class="muted">' . self::e($category->description) . '</p>';
            }
            $items = $articles[$category->key] ?? [];
            if ($items === []) {
                $body .= '<p class="muted">Bu kategoride yayımlanmış soru bulunmuyor.</p>';
            } else {
                foreach ($items as $article) {
                    $href = $basePath->prepend(
                        '/faq/' . rawurlencode($article->language) . '/' . rawurlencode($article->slug),
                    );
                    $body .= '<article class="search-hit"><div class="search-hit-type">'
                        . self::e($article->language) . '</div><h3><a href="' . self::e($href) . '">'
                        . self::e($article->question) . '</a></h3>';
                    if ($article->tags !== []) {
                        $body .= '<div class="muted">' . self::e(implode(' · ', $article->tags)) . '</div>';
                    }
                    $body .= '</article>';
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

        $body = '<article class="card"><div class="search-hit-type">' . self::e($view->category->label)
            . ' · ' . self::e($article->language) . '</div><h1>' . self::e($article->question) . '</h1>'
            . '<div class="about">' . nl2br(self::e($article->answer), false) . '</div>';
        if ($article->tags !== []) {
            $body .= '<p class="muted">Etiketler: ' . self::e(implode(', ', $article->tags)) . '</p>';
        }
        $body .= '<hr><p class="muted">' . self::e($analytics) . '</p>';
        if ($voted) {
            $body .= '<div class="notice success">Değerlendirmeniz kaydedildi.</div>';
        }
        if ($authenticated && $csrfToken !== null) {
            $action = $basePath->prepend(
                '/faq/' . rawurlencode($article->language) . '/' . rawurlencode($article->slug),
            );
            $body .= '<form method="post" action="' . self::e($action) . '" class="presence-settings">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
                . '<span>Bu cevap faydalı mıydı?</span>'
                . '<button type="submit" name="helpful" value="1">Evet</button>'
                . '<button type="submit" name="helpful" value="0">Hayır</button></form>';
        } else {
            $body .= '<p class="muted">Faydalı değerlendirmesi yapmak için oturum açın.</p>';
        }
        $body .= '</article>';

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
        $notice = $updated ? '<div class="notice success">SSS içeriği güncellendi.</div>' : '';
        if ($error !== null) {
            $notice .= '<div class="notice error">' . self::e($error) . '</div>';
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

        $body = '<section class="card"><h1>SSS Yönetimi</h1><p class="muted">'
            . 'Kategori, içerik, görünürlük, dil, sıralama, SEO ve import/export yönetimi.</p>'
            . $notice
            . '<p><a href="' . self::e($basePath->prepend('/faq/manage?export=1')) . '">JSON dışa aktar</a>'
            . ' · <a href="' . self::e($basePath->prepend('/faq/manage/support-drafts')) . '">Destekten gelen SSS taslakları</a></p>'
            . '<details open><summary>Kategori kaydet</summary><form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="category_save">'
            . '<label><span>Anahtar</span><input name="key" maxlength="64" required></label>'
            . '<label><span>Başlık</span><input name="label" maxlength="120" required></label>'
            . '<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="500"></textarea></label>'
            . '<label><span>Dil</span><input name="language" value="tr" maxlength="32" required></label>'
            . '<label><span>Görünürlük</span><select name="visibility">' . $visibility . '</select></label>'
            . '<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="65535" value="100"></label>'
            . '<label><input type="checkbox" name="active" value="1" checked> Aktif</label>'
            . '<div class="search-actions"><button type="submit">Kategoriyi kaydet</button></div></form></details>'
            . '<details><summary>Makale kaydet</summary><form method="post" action="' . $action . '" class="search-form">'
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
            . '<details><summary>JSON içe aktar</summary><form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="import">'
            . '<label class="search-wide"><span>Forwext FAQ JSON</span><textarea name="json" maxlength="5000000" rows="12" required></textarea></label>'
            . '<div class="search-actions"><button type="submit">İçe aktar</button></div></form></details>'
            . '<section class="section"><h2>Mevcut içerik</h2><p class="muted">'
            . count($categories) . ' kategori · ' . count($articles) . ' makale</p><ul>';
        foreach ($articles as $article) {
            $summary = $helpful[$article->articleId->value()] ?? null;
            $metric = $summary === null || $summary->total() === 0
                ? '0 değerlendirme'
                : $summary->total() . ' değerlendirme / %'
                    . number_format(($summary->ratio() ?? 0.0) * 100, 0) . ' faydalı';
            $body .= '<li><code>' . self::e($article->articleId->value()) . '</code> — '
                . self::e($article->question) . ' [' . self::e($article->language . '/' . $article->visibility->value)
                . '] — ' . self::e($metric) . '</li>';
        }
        $body .= '</ul></section></section>';

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
