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
            $notice = '<div class="notification-settings-notice" role="status">Hata bildirimin alındı. Kayıt kimliği: <code>'
                . self::e($createdReportId) . '</code></div>';
        } elseif ($error) {
            $notice = '<div class="auth-entry-error" role="alert">Hata bildirimi oluşturulamadı. Alanları ve ek dosyaları kontrol edip tekrar dene.</div>';
        }

        if ($categories === []) {
            $body = '<section class="bug-form-page discovery-page"><header class="surface-head bug-form-head"><div>'
                . '<span class="forum-eyebrow">HATA BİLDİRİMİ</span><h1>Hata bildir</h1>'
                . '<p>Şu anda kullanılabilir hata kategorisi bulunmuyor.</p></div>'
                . '<a class="fx-btn" href="' . self::e($basePath->prepend('/bugs')) . '">Kayıtlarıma dön</a></header></section>';
            return ProfileHtml::page('Hata bildir', $body, $basePath, authenticated: true);
        }

        $options = '';
        foreach ($categories as $category) {
            $options .= '<option value="' . self::e($category->key) . '">' . self::e($category->label) . '</option>';
        }

        $source = $sourcePath === null
            ? '<span class="muted">Kaynak sayfa otomatik belirlenemedi.</span>'
            : '<code>' . self::e($sourcePath) . '</code>';

        $body = '<section class="bug-form-page discovery-page"><header class="surface-head bug-form-head"><div>'
            . '<span class="forum-eyebrow">HATA BİLDİRİMİ</span><h1>Hata bildir</h1>'
            . '<p>Sorunu tekrar üretilebilir şekilde anlat; teknik bağlam güvenli biçimde ayrıca toplanır.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/bugs')) . '">Kayıtlarıma dön</a></header>'
            . $notice
            . '<div class="bug-form-grid"><section class="surface-panel bug-form-panel"><form method="post" enctype="multipart/form-data" action="'
            . self::e($basePath->prepend('/bugs/report')) . '" class="support-intake-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . '<input type="hidden" name="source_path" value="' . self::e($sourcePath ?? '') . '">'
            . '<label><span>Kategori</span><select name="category" required>' . $options . '</select></label>'
            . '<label><span>Başlık</span><input name="title" maxlength="200" required></label>'
            . '<label><span>Kısa özet</span><textarea name="summary" maxlength="5000" rows="4" required></textarea></label>'
            . '<label><span>Tekrar üretme adımları</span><textarea name="reproduction_steps" maxlength="10000" rows="7" required '
            . 'placeholder="1. ...&#10;2. ...&#10;3. ..."></textarea></label>'
            . '<label><span>Beklenen sonuç</span><textarea name="expected_result" maxlength="10000" rows="5" required></textarea></label>'
            . '<label><span>Gerçekleşen sonuç</span><textarea name="actual_result" maxlength="10000" rows="5" required></textarea></label>'
            . '<label><span>Screenshot / dosya</span><input type="file" name="attachments[]" multiple '
            . 'accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,application/zip,text/plain"></label>'
            . '<div class="support-intake-actions"><button class="fx-btn fx-btn--primary" type="submit">Hata bildirimini gönder</button></div>'
            . '</form></section><aside class="surface-panel bug-form-guidance"><div><span class="forum-eyebrow">BAĞLAM</span>'
            . '<h2>Gönderim bilgileri</h2></div><dl><div><dt>Kaynak sayfa</dt><dd>' . $source . '</dd></div>'
            . '<div><dt>Ek sınırı</dt><dd>En fazla 5 dosya · dosya başına 25 MiB</dd></div>'
            . '<div><dt>Gizlilik</dt><dd>Teknik bağlam güvenli biçimde toplanır; görsel metadata temizlenebilir.</dd></div></dl>'
            . '<div class="bug-form-checklist"><strong>Daha hızlı inceleme için</strong><span>Tekrar üretme adımlarını sırayla yaz.</span>'
            . '<span>Beklenen ve gerçekleşen sonucu ayrı anlat.</span><span>Gerekliyse ekran görüntüsü veya log ekle.</span></div>'
            . '</aside></div></section>';

        return ProfileHtml::page('Hata bildir', $body, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
