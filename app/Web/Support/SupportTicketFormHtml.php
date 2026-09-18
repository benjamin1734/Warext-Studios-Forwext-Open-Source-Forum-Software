<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Support\Intake\SupportContextType;
use Forwext\Core\Support\Intake\SupportFieldDefinition;
use Forwext\Core\Support\Intake\SupportFieldType;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Routing\BasePath;

final class SupportTicketFormHtml
{
    /**
     * @param list<SupportCategory> $categories
     * @param list<SupportFieldDefinition> $fields
     */
    public static function page(
        array $categories,
        ?SupportCategory $selectedCategory,
        array $fields,
        string $csrfToken,
        BasePath $basePath,
        ?string $createdTicketId = null,
        bool $error = false,
        ?SupportContextType $contextType = null,
        ?string $contextId = null,
    ): string {
        $notice = '';
        if ($createdTicketId !== null) {
            $notice = '<div class="notice success">Destek talebin oluşturuldu. Talep kimliği: <code>'
                . self::e($createdTicketId) . '</code></div>';
        } elseif ($error) {
            $notice = '<div class="notice error">Talep oluşturulamadı. Alanları, bağlantıyı ve ek dosyaları kontrol edip tekrar dene.</div>';
        }

        if ($categories === []) {
            $body = '<section class="card settings"><h1>Destek talebi</h1>'
                . '<div class="notice error">Şu anda kullanılabilir destek kategorisi bulunmuyor.</div></section>';
            return ProfileHtml::page('Destek talebi', $body, $basePath, authenticated: true);
        }

        $categoryOptions = '';
        foreach ($categories as $category) {
            $categoryOptions .= '<option value="' . self::e($category->key) . '"'
                . ($selectedCategory?->key === $category->key ? ' selected' : '') . '>'
                . self::e($category->label) . '</option>';
        }

        $categoryChooser = '<form method="get" action="' . self::e($basePath->prepend('/support/new'))
            . '" class="search-form"><label><span>Kategori</span><select name="category">'
            . $categoryOptions . '</select></label><div class="search-actions">'
            . '<button type="submit">Formu göster</button></div></form>';

        $form = '';
        if ($selectedCategory !== null) {
            $dynamic = '';
            foreach ($fields as $field) {
                $dynamic .= self::field($field);
            }
            if ($dynamic === '') {
                $dynamic = '<p class="muted">Bu kategori için ek alan gerekmiyor.</p>';
            }

            $contextOptions = '<option value="">Bağlantı yok</option>';
            foreach (SupportContextType::cases() as $type) {
                $contextOptions .= '<option value="' . self::e($type->value) . '"'
                    . ($contextType === $type ? ' selected' : '') . '>'
                    . self::e($type->label()) . '</option>';
            }

            $form = '<section class="card section"><h2>' . self::e($selectedCategory->label) . '</h2>'
                . ($selectedCategory->description === '' ? '' : '<p class="muted">'
                    . self::e($selectedCategory->description) . '</p>')
                . '<form method="post" enctype="multipart/form-data" action="'
                . self::e($basePath->prepend('/support/new')) . '" class="presence-settings">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrfToken) . '">'
                . '<input type="hidden" name="category" value="' . self::e($selectedCategory->key) . '">'
                . '<label><span>Konu</span><input name="subject" maxlength="200" required></label>'
                . '<label><span>Açıklama</span><textarea name="description" maxlength="10000" rows="8" required></textarea></label>'
                . $dynamic
                . '<details><summary>İlgili içerik veya hesabı bağla</summary>'
                . '<p class="muted">İsteğe bağlıdır. Konu, hesap veya marketplace ilan kimliğiyle talebe bağlam ekleyebilirsin.</p>'
                . '<label><span>Bağlam türü</span><select name="context_type">' . $contextOptions . '</select></label>'
                . '<label><span>Bağlam kimliği</span><input name="context_id" maxlength="32" pattern="[a-f0-9]{32}" value="'
                . self::e($contextId ?? '') . '"></label></details>'
                . '<label><span>Ek dosyalar</span><input type="file" name="attachments[]" multiple></label>'
                . '<p class="muted">En fazla 5 dosya. İzin verilen içerikler: yaygın görseller, PDF, ZIP ve düz metin; dosya başına en fazla 25 MiB.</p>'
                . '<button type="submit">Talebi oluştur</button></form></section>';
        }

        $body = '<section class="card settings"><h1>Destek talebi aç</h1>'
            . '<p class="muted">Önce doğru kategoriyi seç. Kategoriye özel alanlar yalnız gerektiğinde gösterilir.</p>'
            . $notice . $categoryChooser . '</section>' . $form;

        return ProfileHtml::page('Destek talebi aç', $body, $basePath, authenticated: true);
    }

    private static function field(SupportFieldDefinition $field): string
    {
        $name = 'field_' . $field->fieldKey;
        $required = $field->required ? ' required' : '';
        $help = $field->helpText === '' ? '' : '<span class="muted">' . self::e($field->helpText) . '</span>';

        $input = match ($field->type) {
            SupportFieldType::Text => '<input name="' . self::e($name) . '" maxlength="' . $field->maxLength . '"' . $required . '>',
            SupportFieldType::Textarea => '<textarea name="' . self::e($name) . '" maxlength="' . $field->maxLength
                . '" rows="5"' . $required . '></textarea>',
            SupportFieldType::Select => self::select($field, $name),
            SupportFieldType::Checkbox => '<input type="checkbox" name="' . self::e($name) . '" value="1"' . $required . '>',
        };

        return '<label><span>' . self::e($field->label) . '</span>' . $input . $help . '</label>';
    }

    private static function select(SupportFieldDefinition $field, string $name): string
    {
        $options = $field->required ? '<option value="">Seç</option>' : '<option value="">Belirtilmedi</option>';
        foreach ($field->choices as $key => $label) {
            $options .= '<option value="' . self::e($key) . '">' . self::e($label) . '</option>';
        }
        return '<select name="' . self::e($name) . '"' . ($field->required ? ' required' : '') . '>'
            . $options . '</select>';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
