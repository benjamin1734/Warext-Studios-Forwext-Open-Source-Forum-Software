<?php

declare(strict_types=1);

namespace Forwext\App\Web\Marketplace;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Marketplace\MarketplaceCategory;
use Forwext\Core\Marketplace\MarketplaceCustomFieldDefinition;
use Forwext\Core\Marketplace\MarketplaceCustomFieldType;
use Forwext\Core\Routing\BasePath;

final class MarketplaceCategoryHtml
{
    /**
     * @param list<MarketplaceCategory> $categories
     * @param array<string,list<MarketplaceCustomFieldDefinition>> $fields
     */
    public static function manage(
        array $categories,
        array $fields,
        ?MarketplaceCategory $selectedCategory,
        ?MarketplaceCustomFieldDefinition $selectedField,
        BasePath $basePath,
        string $csrf,
        bool $updated,
    ):string{
        $action=self::e($basePath->prepend('/admin/marketplace/categories'));
        $notice=$updated?'<div class="notice success">Marketplace kategori ayarları kaydedildi.</div>':'';

        $byId=[];
        foreach($categories as $category)$byId[$category->categoryId->value()]=$category;

        $body='<section class="card"><h1>Marketplace Kategori Yönetimi</h1>'
            .'<p class="muted">Kategori/alt kategori ağacını ve kategoriye özel ilan alanlarını yönetin. '
            .'İlan liste/grid, arama, review ve seller UX’i 14.02 kapsamındadır.</p>'.$notice
            .'<section class="section"><h2>Kategoriler</h2>';

        if($categories===[])$body.='<div class="empty">Henüz marketplace kategorisi yok.</div>';
        foreach($categories as $category){
            $parent=$category->parentCategoryId===null?null:($byId[$category->parentCategoryId->value()]??null);
            $body.='<article class="search-hit"><div class="search-hit-type">'
                .($category->enabled?'Etkin':'Kapalı').' · Sıra '.$category->sortOrder.'</div><h3><a href="'
                .self::e($basePath->prepend('/admin/marketplace/categories?category='.rawurlencode($category->categoryId->value())))
                .'">'.self::e($category->name).'</a></h3><p class="muted"><code>'.self::e($category->key)
                .'</code> · /'.self::e($category->slug)
                .($parent===null?'':' · Üst: '.self::e($parent->name)).'</p>';

            foreach($fields[$category->categoryId->value()]??[] as $field){
                $body.='<a href="'.self::e($basePath->prepend(
                    '/admin/marketplace/categories?category='.rawurlencode($category->categoryId->value())
                    .'&field='.rawurlencode($field->fieldId->value())
                )).'">'.self::e($field->label).'</a> <span class="muted">('.self::e($field->type->value)
                    .($field->required?', zorunlu':'').')</span> · ';
            }
            $body.='</article>';
        }
        $body.='</section>';

        $category=$selectedCategory;
        $parentOptions='<option value="">Üst kategori yok</option>';
        foreach($categories as $candidate){
            if($category!==null&&$candidate->categoryId->equals($category->categoryId))continue;
            $selected=$category?->parentCategoryId?->equals($candidate->categoryId)===true?' selected':'';
            $parentOptions.='<option value="'.self::e($candidate->categoryId->value()).'"'.$selected.'>'
                .self::e($candidate->name).'</option>';
        }

        $body.='<section class="section"><h2>'.($category===null?'Yeni kategori':'Kategoriyi düzenle').'</h2>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="category_save"><input type="hidden" name="category_id" value="'
            .self::e($category?->categoryId->value()??'').'">'
            .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($category?->key??'').'"></label>'
            .'<label><span>Slug</span><input name="slug" maxlength="96" required value="'.self::e($category?->slug??'').'"></label>'
            .'<label><span>Ad</span><input name="name" maxlength="120" required value="'.self::e($category?->name??'').'"></label>'
            .'<label><span>Üst kategori</span><select name="parent_category_id">'.$parentOptions.'</select></label>'
            .'<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="4000" rows="4">'
            .self::e($category?->description??'').'</textarea></label>'
            .'<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="65535" value="'.($category?->sortOrder??100).'"></label>'
            .'<label><input type="checkbox" name="enabled" value="1"'.(($category?->enabled??true)?' checked':'').'> Etkin</label>'
            .'<div class="search-actions"><button type="submit">Kategoriyi kaydet</button><a href="'
            .self::e($basePath->prepend('/admin/marketplace/categories')).'">Yeni kategori</a></div></form></section>';

        $field=$selectedField;
        $categoryOptions='';
        foreach($categories as $candidate){
            $chosen=$field?->categoryId->equals($candidate->categoryId)===true
                ||($field===null&&$category?->categoryId->equals($candidate->categoryId)===true);
            $categoryOptions.='<option value="'.self::e($candidate->categoryId->value()).'"'.($chosen?' selected':'').'>'
                .self::e($candidate->name).'</option>';
        }
        $type=$field?->type??MarketplaceCustomFieldType::Text;
        $body.='<section class="section"><h2>'.($field===null?'Yeni özel alan':'Özel alanı düzenle').'</h2>'
            .'<form method="post" action="'.$action.'" class="search-form">'.self::csrf($csrf)
            .'<input type="hidden" name="action" value="field_save"><input type="hidden" name="field_id" value="'
            .self::e($field?->fieldId->value()??'').'">'
            .'<label><span>Kategori</span><select name="category_id" required>'.$categoryOptions.'</select></label>'
            .'<label><span>Anahtar</span><input name="key" maxlength="64" required value="'.self::e($field?->key??'').'"></label>'
            .'<label><span>Etiket</span><input name="label" maxlength="120" required value="'.self::e($field?->label??'').'"></label>'
            .'<label><span>Tür</span><select name="field_type">'
            .self::option('text','Metin',$type->value).self::option('integer','Tam sayı',$type->value)
            .self::option('boolean','Evet/Hayır',$type->value).self::option('select','Seçim',$type->value).'</select></label>'
            .'<label class="search-wide"><span>Seçenekler (yalnız Select; satır başına bir seçenek)</span><textarea name="options" maxlength="12000" rows="6">'
            .self::e(implode("\n",$field?->options??[])).'</textarea></label>'
            .'<label><span>Sıra</span><input type="number" name="sort_order" min="0" max="1000" value="'.($field?->sortOrder??100).'"></label>'
            .'<label><input type="checkbox" name="required" value="1"'.(($field?->required??false)?' checked':'').'> Zorunlu</label>'
            .'<label><input type="checkbox" name="active" value="1"'.(($field?->active??true)?' checked':'').'> Aktif</label>'
            .'<div class="search-actions"><button type="submit">Özel alanı kaydet</button></div></form></section>'
            .'<p class="muted">Kategori derinliği backend’de en fazla 8 seviye; cycle/self-parent kabul edilmez. '
            .'Custom field değer tipi ilan kaydında tekrar backend tarafından doğrulanır.</p></section>';

        return ProfileHtml::page('Marketplace Kategori Yönetimi',$body,$basePath,authenticated:true);
    }

    private static function csrf(string $token):string
    {
        return '<input type="hidden" name="_csrf" value="'.self::e($token).'">';
    }

    private static function option(string $value,string $label,string $selected):string
    {
        return '<option value="'.self::e($value).'"'.($value===$selected?' selected':'').'>'.self::e($label).'</option>';
    }

    private static function e(string $value):string{return ProfileHtml::escape($value);}
}
