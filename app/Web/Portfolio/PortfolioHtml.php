<?php

declare(strict_types=1);

namespace Forwext\App\Web\Portfolio;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Portfolio\PortfolioCategory;
use Forwext\Core\Portfolio\PortfolioComment;
use Forwext\Core\Portfolio\PortfolioProject;
use Forwext\Core\Portfolio\PortfolioReactionSummary;
use Forwext\Core\Routing\BasePath;

final class PortfolioHtml
{
    /**
     * @param list<PortfolioProject> $projects
     * @param list<PortfolioCategory> $categories
     */
    public static function index(
        array $projects,
        array $categories,
        BasePath $basePath,
        bool $authenticated,
        bool $canCreate,
    ): string {
        $actions = $canCreate
            ? '<p><a href="' . self::e($basePath->prepend('/portfolio/manage')) . '">Yeni proje oluştur</a></p>'
            : '';
        $categoryLabels = [];
        foreach ($categories as $category) {
            $categoryLabels[$category->key] = $category->label;
        }

        $body = '<section class="card"><h1>Portfolyo</h1>'
            . '<p class="muted">Topluluk üyelerinin projeleri, çalışmaları ve öne çıkan üretimleri.</p>'
            . $actions;

        if ($projects === []) {
            $body .= '<div class="empty">Henüz yayımlanmış portfolyo projesi bulunmuyor.</div>';
        } else {
            $body .= '<div class="search-results">';
            foreach ($projects as $project) {
                $body .= self::projectCard($project, $categoryLabels[$project->categoryKey] ?? $project->categoryKey, $basePath);
            }
            $body .= '</div>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Portfolyo', $body, $basePath, authenticated:$authenticated);
    }

    /**
     * @param list<PortfolioComment> $comments
     */
    public static function project(
        PortfolioProject $project,
        array $comments,
        PortfolioReactionSummary $reactions,
        BasePath $basePath,
        bool $authenticated,
        ?string $csrfToken,
        bool $canManage,
        ?string $notice = null,
    ): string {
        $body = '<article class="card"><div class="search-hit-type">'
            . self::e($project->categoryKey)
            . ($project->featured ? ' · Öne Çıkan' : '')
            . '</div><h1>' . self::e($project->title) . '</h1>';

        if ($project->summary !== '') {
            $body .= '<p class="muted">' . self::e($project->summary) . '</p>';
        }
        if ($notice !== null) {
            $body .= '<div class="notice success">' . self::e($notice) . '</div>';
        }

        if ($project->media !== []) {
            $body .= '<div class="portfolio-media">';
            foreach ($project->media as $media) {
                $body .= '<figure><img loading="lazy" decoding="async" referrerpolicy="no-referrer" src="'
                    . self::e($basePath->prepend($media->path)) . '" alt="' . self::e($media->alt) . '"></figure>';
            }
            $body .= '</div>';
        }

        $body .= '<div class="about">' . nl2br(self::e($project->description), false) . '</div>';
        if ($project->tags !== []) {
            $body .= '<p class="muted">Etiketler: ' . self::e(implode(', ', $project->tags)) . '</p>';
        }
        $body .= '<p class="muted">Durum: ' . self::e($project->state->value)
            . ' · Güncelleme: ' . self::e($project->updatedAt->format('Y-m-d H:i')) . ' UTC</p>';

        if ($canManage) {
            $body .= '<p><a href="' . self::e($basePath->prepend('/portfolio/manage?project=' . rawurlencode($project->projectId->value())))
                . '">Projeyi düzenle</a></p>';
        }

        $body .= '<section class="section"><h2>Tepkiler</h2><p class="muted">'
            . $reactions->total . ' tepki · skor ' . $reactions->score . '</p>';
        if ($reactions->counts !== []) {
            $parts = [];
            foreach ($reactions->counts as $key => $count) {
                $parts[] = self::e($key) . ': ' . (int) $count;
            }
            $body .= '<p>' . implode(' · ', $parts) . '</p>';
        }
        if ($authenticated && $csrfToken !== null) {
            $action = self::e($basePath->prepend('/portfolio/' . rawurlencode($project->projectId->value())));
            $body .= '<form method="post" action="' . $action . '" class="presence-settings">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="react">'
                . '<label><span>Tepki</span><select name="reaction">'
                . '<option value="like">Like</option><option value="love">Love</option>'
                . '<option value="haha">Haha</option><option value="wow">Wow</option>'
                . '<option value="sad">Sad</option><option value="angry">Angry</option></select></label>'
                . '<button type="submit">Tepki ver</button></form>'
                . '<form method="post" action="' . $action . '" class="presence-settings">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="unreact"><button type="submit">Tepkiyi kaldır</button></form>';
        }
        $body .= '</section>';

        $body .= '<section class="section"><h2>Yorumlar</h2>';
        if ($comments === []) {
            $body .= '<p class="muted">Henüz yorum yapılmamış.</p>';
        } else {
            foreach ($comments as $comment) {
                $body .= '<article class="search-hit"><div class="about">'
                    . nl2br(self::e($comment->body), false) . '</div><div class="muted">'
                    . self::e($comment->createdAt->format('Y-m-d H:i')) . ' UTC</div></article>';
            }
        }
        if ($authenticated && $csrfToken !== null) {
            $action = self::e($basePath->prepend('/portfolio/' . rawurlencode($project->projectId->value())));
            $body .= '<form method="post" action="' . $action . '" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="comment">'
                . '<label class="search-wide"><span>Yorum</span><textarea name="body" maxlength="10000" rows="5" required></textarea></label>'
                . '<div class="search-actions"><button type="submit">Yorumu gönder</button></div></form>';
        } else {
            $body .= '<p class="muted">Yorum ve tepki için oturum açın.</p>';
        }
        $body .= '</section></article>';

        return ProfileHtml::page($project->title, $body, $basePath, authenticated:$authenticated);
    }

    /**
     * @param list<PortfolioCategory> $categories
     */
    public static function manage(
        array $categories,
        ?PortfolioProject $project,
        BasePath $basePath,
        string $csrfToken,
        bool $canManageAll,
        bool $updated,
        ?string $error = null,
    ): string {
        $action = self::e($basePath->prepend('/portfolio/manage'));
        $notice = $updated ? '<div class="notice success">Portfolyo projesi güncellendi.</div>' : '';
        if ($error !== null) {
            $notice .= '<div class="notice error">' . self::e($error) . '</div>';
        }

        $categoryOptions = '';
        foreach ($categories as $category) {
            $selected = $project?->categoryKey === $category->key ? ' selected' : '';
            $categoryOptions .= '<option value="' . self::e($category->key) . '"' . $selected . '>'
                . self::e($category->label) . '</option>';
        }

        $tagValue = $project === null ? '' : implode(', ', $project->tags);
        $body = '<section class="card"><h1>Portfolyo Projesi</h1>'
            . '<p class="muted">Proje metni ortak yazım/AI moderasyon hattından geçirilir; yayımlama gerektiğinde onaya düşebilir.</p>'
            . $notice
            . '<form method="post" action="' . $action . '" class="search-form">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="project_save">'
            . '<input type="hidden" name="project_id" value="' . self::e($project?->projectId->value() ?? '') . '">'
            . '<label><span>Kategori</span><select name="category" required>' . $categoryOptions . '</select></label>'
            . '<label><span>Slug</span><input name="slug" maxlength="160" required value="' . self::e($project?->slug ?? '') . '"></label>'
            . '<label class="search-wide"><span>Başlık</span><input name="title" maxlength="180" required value="' . self::e($project?->title ?? '') . '"></label>'
            . '<label class="search-wide"><span>Kısa özet</span><textarea name="summary" maxlength="500" rows="3">'
            . self::e($project?->summary ?? '') . '</textarea></label>'
            . '<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="100000" rows="14" required>'
            . self::e($project?->description ?? '') . '</textarea></label>'
            . '<label class="search-wide"><span>Etiketler</span><input name="tags" maxlength="2200" value="' . self::e($tagValue)
            . '" placeholder="php, forum, açık-kaynak"></label>'
            . '<label><input type="checkbox" name="publish" value="1"'
            . ($project?->state->value === 'published' || $project?->state->value === 'pending' ? ' checked' : '')
            . '> Yayımla / incelemeye gönder</label>';

        if ($canManageAll) {
            $body .= '<label><input type="checkbox" name="featured" value="1"'
                . ($project?->featured ? ' checked' : '') . '> Öne çıkar</label>';
        }

        $body .= '<div class="search-actions"><button type="submit">Kaydet</button>'
            . '<a href="' . self::e($basePath->prepend('/portfolio')) . '">Portfolyoya dön</a></div></form>';

        if ($project !== null) {
            $mediaAction = self::e($basePath->prepend(
                '/portfolio/' . rawurlencode($project->projectId->value()) . '/media',
            ));
            $body .= '<section class="section"><h2>Proje medyası</h2>'
                . '<p class="muted">Görseller ortak MIME/signature, boyut, piksel ve EXIF güvenlik denetiminden geçirilir. En fazla 12 görsel.</p>';
            if ($project->media !== []) {
                $body .= '<div class="portfolio-media">';
                foreach ($project->media as $media) {
                    $body .= '<figure><img loading="lazy" decoding="async" referrerpolicy="no-referrer" src="'
                        . self::e($basePath->prepend($media->path)) . '" alt="' . self::e($media->alt) . '">';
                    if ($media->mediaId !== null) {
                        $body .= '<form method="post" action="' . $mediaAction . '">'
                            . self::csrf($csrfToken)
                            . '<input type="hidden" name="action" value="delete">'
                            . '<input type="hidden" name="media_id" value="' . self::e($media->mediaId->value()) . '">'
                            . '<button type="submit">Görseli kaldır</button></form>';
                    }
                    $body .= '</figure>';
                }
                $body .= '</div>';
            }
            $body .= '<form method="post" action="' . $mediaAction . '" enctype="multipart/form-data" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="upload">'
                . '<label class="search-wide"><span>Görsel</span><input type="file" name="file" '
                . 'accept="image/jpeg,image/png,image/gif,image/webp" required></label>'
                . '<label class="search-wide"><span>Alternatif metin</span><input name="alt" maxlength="200" '
                . 'placeholder="Görseli erişilebilir biçimde açıklayın"></label>'
                . '<div class="search-actions"><button type="submit">Görsel yükle</button></div></form></section>';
        }

        if ($canManageAll) {
            $body .= '<details class="section"><summary>Kategori yönetimi</summary>'
                . '<form method="post" action="' . $action . '" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="category_save">'
                . '<label><span>Anahtar</span><input name="key" maxlength="64" required></label>'
                . '<label><span>Başlık</span><input name="label" maxlength="120" required></label>'
                . '<label class="search-wide"><span>Açıklama</span><textarea name="category_description" maxlength="500"></textarea></label>'
                . '<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="65535" value="100"></label>'
                . '<label><input type="checkbox" name="active" value="1" checked> Aktif</label>'
                . '<div class="search-actions"><button type="submit">Kategoriyi kaydet</button></div></form></details>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Portfolyo Yönetimi', $body, $basePath, authenticated:true);
    }

    private static function projectCard(PortfolioProject $project, string $category, BasePath $basePath): string
    {
        $href = self::e($basePath->prepend('/portfolio/' . rawurlencode($project->projectId->value())));
        $media = $project->media[0] ?? null;
        $thumb = $media === null
            ? ''
            : '<img loading="lazy" decoding="async" referrerpolicy="no-referrer" src="'
                . self::e($basePath->prepend($media->path)) . '" alt="' . self::e($media->alt) . '">';
        return '<article class="search-hit">' . $thumb . '<div class="search-hit-type">'
            . self::e($category) . ($project->featured ? ' · Öne Çıkan' : '')
            . '</div><h2><a href="' . $href . '">' . self::e($project->title) . '</a></h2>'
            . ($project->summary === '' ? '' : '<p>' . self::e($project->summary) . '</p>')
            . '</article>';
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
