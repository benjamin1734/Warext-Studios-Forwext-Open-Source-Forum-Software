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
        ?BugReportCategory $selectedCategory,
        string $csrfToken,
        BasePath $basePath,
        ?string $createdReportId = null,
        bool $error = false,
        ?string $sourcePath = null,
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

        $categoryOptions = '';
        foreach ($categories as $category) {
            $categoryOptions .= '<option value="' . self::e($category->key) . '"'
                . ($selectedCategory?->key === $category->key ? ' selected' : '') . '>'
                . self::e($category->label) . '</option>';
        }

        $source = '';
        if ($sourcePath !== null) {
            $source = '<div class="search-wide muted">Bildirilen sayfa: <code>' . self::e($sourcePath) . '</code></div>'
                . '<input type="hidden" name="source_path" value="' . self::e($sourcePath) . '">';
        }

        $description = $selectedCategory?->description ?? '';
        $body = '<section class="card settings"><h1>Hata bildir</h1>'
            . '<p class="muted">Sorunu yeniden üretebilmemiz için ne yaptığını, ne beklediğini ve gerçekte ne olduğunu açıkça yaz.</p>'
            . $notice
            . '<form method="post" enctype="multipart/form-data" action="'
            . self::e($basePath->prepend('/bugs/new')) . '" class="search-form bug-report-form">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
            . $source
            . '<label><span>Kategori</span><select name="category" required>' . $categoryOptions . '</select></label>'
            . '<label><span>Başlık</span><input name="title" maxlength="200" required></label>'
            . ($description === '' ? '' : '<div class="search-wide muted">' . self::e($description) . '</div>')
            . '<label class="search-wide"><span>Kısa açıklama</span>'
            . '<textarea name="summary" maxlength="5000" rows="5" required></textarea></label>'
            . '<label class="search-wide"><span>Tekrar üretme adımları</span>'
            . '<textarea name="reproduction_steps" maxlength="10000" rows="7" '
            . 'placeholder="1. ...&#10;2. ...&#10;3. ..." required></textarea></label>'
            . '<label class="search-wide"><span>Beklenen sonuç</span>'
            . '<textarea name="expected_result" maxlength="5000" rows="4" required></textarea></label>'
            . '<label class="search-wide"><span>Gerçekleşen sonuç</span>'
            . '<textarea name="actual_result" maxlength="5000" rows="4" required></textarea></label>'
            . '<label class="search-wide"><span>Ekran görüntüsü / dosya</span>'
            . '<input type="file" name="attachments[]" multiple '
            . 'accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,application/zip,text/plain"></label>'
            . '<p class="search-wide muted">En fazla 5 dosya; dosya başına en fazla 25 MiB. '
            . 'Görseller, PDF, ZIP ve düz metin kabul edilir. Yüklemeler private storage içinde tutulur.</p>'
            . '<div class="search-wide muted">Teknik bağlam otomatik eklenir. Query string, çerez, istek gövdesi, ham User-Agent ve IP adresi tanılama kaydına yazılmaz.</div>'
            . '<div class="search-actions"><button type="submit">Hata bildirimini gönder</button></div>'
            . '</form></section>';

        return ProfileHtml::page('Hata bildir', $body, $basePath, authenticated: true);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
