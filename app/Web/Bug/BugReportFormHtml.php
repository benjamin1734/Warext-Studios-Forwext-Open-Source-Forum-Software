<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Routing\BasePath;

final class BugReportFormHtml
{
    /**
     * @param list<BugReportCategory> $categories
     */
    public static function page(
        array $categories,
        string $csrfToken,
        BasePath $basePath,
        ?string $sourcePath = null,
        ?string $createdReportId = null,
        bool $error = false,
    ): string {
        $notice = '';
        if ($createdReportId !== null) {
            $notice = '<div class="notice success">Hata bildirimin alındı. Kayıt kimliği: <code>'
                . self::e($createdReportId) . '</code></div>';
        } elseif ($error) {
            $notice = '<div class="notice error">Hata bildirimi oluşturulamadı. Alanları ve ek dosyaları kontrol edip tekrar dene.</div>';
        }

        if ($categories === []) {
            $body = '<section class="card settings"><h1>Hata bildir</h1>'
                . '<div class="notice error">Şu anda kullanılabilir hata kategorisi bulunmuyor.</div></section>';
            return ProfileHtml::page('Hata bildir', $body, $basePath, authenticated: true);
        }

        $options = '';
        foreach ($categories as $category) {
            $options .= '<option value="' . self::e($category->key) . '">' . self::e($category->label) . '</option>';
        }

        $source = $sourcePath === null
            ? '<span class="muted">Kaynak sayfa otomatik belirlenemedi.</span>'
            : '<code>' . self::e($sourcePath) . '</code>';

        $body = '<section class="card settings"><h1>Hata bildir</h1>'
            . '<p><a href="' . self::e($basePath->prepend('/bugs/my')) . '">Hata Bildirimlerim</a></p>'
            . '<p class="muted">Sorunu mümkün olduğunca tekrar üretilebilir şekilde anlat. Teknik bağlam güvenli biçimde ayrıca toplanır.</p>'
            . $notice
            . '<form method="post" enctype="multipart/form-data" action="' . self::e($basePath->prepend('/bugs/report'))
            . '" class="presence-settings" style="display:grid;align-items:stretch">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . '<input type="hidden" name="source_path" value="' . self::e($sourcePath ?? '') . '">'
            . '<label><span>Kategori</span><select name="category" required>' . $options . '</select></label>'
            . '<label><span>Başlık</span><input name="title" maxlength="200" required></label>'
            . '<label><span>Kısa özet</span><textarea name="summary" maxlength="5000" rows="4" required></textarea></label>'
            . '<label><span>Tekrar üretme adımları</span><textarea name="reproduction_steps" maxlength="10000" rows="7" required '
            . 'placeholder="1. ...&#10;2. ...&#10;3. ..."></textarea></label>'
            . '<label><span>Beklenen sonuç</span><textarea name="expected_result" maxlength="10000" rows="5" required></textarea></label>'
            . '<label><span>Gerçekleşen sonuç</span><textarea name="actual_result" maxlength="10000" rows="5" required></textarea></label>'
            . '<div><strong>Kaynak sayfa</strong><div>' . $source . '</div></div>'
            . '<label><span>Screenshot / dosya</span><input type="file" name="attachments[]" multiple '
            . 'accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,application/zip,text/plain"></label>'
            . '<p class="muted">En fazla 5 dosya; dosya başına 25 MiB. Görseller güvenli biçimde yeniden işlenebilir ve metadata temizlenebilir.</p>'
            . '<button type="submit">Hata bildirimini gönder</button></form></section>';

        return ProfileHtml::page('Hata bildir', $body, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
