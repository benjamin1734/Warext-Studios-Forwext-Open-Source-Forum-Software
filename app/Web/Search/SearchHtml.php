<?php

declare(strict_types=1);

namespace Forwext\App\Web\Search;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Search\SearchHit;

final class SearchHtml
{
    /**
     * @param list<SearchHit> $hits
     * @param array<string,mixed> $query
     * @param list<string> $savedKeys
     */
    public static function page(
        BasePath $basePath,
        string $text,
        array $hits,
        array $query,
        ?string $error,
        array $savedKeys,
        int $page = 1,
    ): string {
        $action = ProfileHtml::escape($basePath->prepend('/search'));
        $value = static fn (string $key): string => ProfileHtml::escape(is_string($query[$key] ?? null) ? (string) $query[$key] : '');
        $content = '<section class="card"><div class="search-head"><div><h1>Gelişmiş Arama</h1>'
            . '<p class="muted">Forum, konu, mesaj ve üyelerde yetkilerinize göre arayın.</p></div></div>'
            . '<form class="search-form" method="get" action="' . $action . '">'
            . '<label class="search-wide"><span>Arama</span><input name="q" maxlength="500" required value="' . ProfileHtml::escape($text) . '" placeholder="Ne arıyorsunuz?"></label>'
            . '<label><span>İçerik tipi</span><input name="type" value="' . $value('type') . '" placeholder="thread,post,user"></label>'
            . '<label><span>Forum ID</span><input name="forum" value="' . $value('forum') . '" placeholder="Birden fazla: virgülle"></label>'
            . '<label><span>Kullanıcı ID</span><input name="user" value="' . $value('user') . '" placeholder="Yazar / üye"></label>'
            . '<label><span>Prefix ID</span><input name="prefix" value="' . $value('prefix') . '"></label>'
            . '<label><span>Tag ID</span><input name="tag" value="' . $value('tag') . '"></label>'
            . '<label><span>State</span><input name="state" value="' . $value('state') . '" placeholder="visible,active"></label>'
            . '<label><span>Konu tipi</span><input name="thread_type" value="' . $value('thread_type') . '" placeholder="discussion"></label>'
            . '<label><span>Güncellendi: başlangıç</span><input type="date" name="after" value="' . $value('after') . '"></label>'
            . '<label><span>Güncellendi: bitiş</span><input type="date" name="before" value="' . $value('before') . '"></label>';
        if ($savedKeys !== []) {
            $content .= '<label><span>Kayıtlı sorgu</span><select name="saved"><option value="">—</option>';
            foreach ($savedKeys as $key) {
                $selected = $value('saved') === ProfileHtml::escape($key) ? ' selected' : '';
                $content .= '<option value="' . ProfileHtml::escape($key) . '"' . $selected . '>' . ProfileHtml::escape($key) . '</option>';
            }
            $content .= '</select></label>';
        }
        $content .= '<div class="search-actions"><button type="submit">Ara</button><a href="' . $action . '">Filtreleri temizle</a></div></form>';

        if ($error !== null) {
            $content .= '<div class="search-alert" role="alert">' . ProfileHtml::escape($error) . '</div>';
        } elseif ($text !== '') {
            $content .= '<div class="search-results"><div class="search-result-head"><h2>Sonuçlar</h2><span class="muted">Sayfa ' . $page . '</span></div>';
            if ($hits === []) {
                $content .= '<div class="empty">Bu sorgu ve filtrelerle erişebildiğiniz bir sonuç bulunamadı.</div>';
            } else {
                foreach ($hits as $hit) $content .= self::hit($basePath, $hit);
            }
            $content .= '</div>';
        }
        $content .= '</section>';
        return ProfileHtml::page('Gelişmiş Arama', $content, $basePath);
    }

    private static function hit(BasePath $basePath, SearchHit $hit): string
    {
        $label = $hit->title ?? ($hit->documentType . ' #' . $hit->documentId);
        $title = ProfileHtml::escape($label);
        $type = ProfileHtml::escape(self::typeLabel($hit->documentType));
        $id = ProfileHtml::escape($hit->documentId);
        $heading = $title;
        if ($hit->documentType === 'user' && $hit->title !== null) {
            $href = ProfileHtml::escape($basePath->prepend('/members/' . rawurlencode($hit->title)));
            $heading = '<a href="' . $href . '">' . $title . '</a>';
        }
        return '<article class="search-hit"><div class="search-hit-type">' . $type . '</div><h3>' . $heading . '</h3>'
            . '<div class="muted search-hit-id">' . $id . '</div></article>';
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'forum' => 'Forum', 'thread' => 'Konu', 'post' => 'Mesaj', 'user' => 'Üye', default => $type,
        };
    }
}
