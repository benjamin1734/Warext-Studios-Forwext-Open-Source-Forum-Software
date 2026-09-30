<?php

declare(strict_types=1);

namespace Forwext\App\Web\Faq;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\SupportBridge\FaqSupportDraftSuggestion;
use Forwext\Core\Routing\BasePath;

final class FaqSupportDraftHtml
{
    /**
     * @param list<FaqSupportDraftSuggestion> $drafts
     * @param list<FaqCategory> $categories
     */
    public static function page(
        array $drafts,
        array $categories,
        BasePath $basePath,
        string $csrfToken,
        bool $updated=false,
    ):string {
        $action=self::e($basePath->prepend('/faq/manage/support-drafts'));
        $body='<section class="module-manage-page discovery-page"><header class="surface-head module-manage-head"><div>'
            .'<span class="forum-eyebrow">SSS TASLAKLARI</span><h1>Destekten gelen taslaklar</h1>'
            .'<p>Yetkili ticket cevaplarından önerilen SSS içeriklerini incele ve bilgi tabanına aktar.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/faq/manage')).'">SSS yönetimine dön</a></header>'
            .($updated?'<div class="notification-settings-notice" role="status">Taslak güncellendi.</div>':'');

        if($drafts===[]){
            $body.='<section class="surface-panel"><div class="surface-empty"><strong>Bekleyen SSS taslağı yok.</strong>'
                .'<span>Destek cevaplarından önerilen yeni taslaklar burada görünecek.</span></div></section></section>';
            return ProfileHtml::page('SSS taslakları',$body,$basePath,authenticated:true);
        }

        $body.='<section class="surface-panel faq-draft-panel"><header><h2>Bekleyen öneriler</h2><span>'
            .count($drafts).'</span></header><div class="faq-draft-list">';

        foreach($drafts as $draft){
            $options='';
            foreach($categories as $category){
                $selected=$draft->suggestedCategoryKey===$category->key?' selected':'';
                $options.='<option value="'.self::e($category->key).'"'.$selected.'>'
                    .self::e($category->label.' ('.$category->language.')').'</option>';
            }
            $ticketHref=$basePath->prepend('/support/tickets/'.rawurlencode($draft->ticketId->value()));

            $body.='<article class="faq-draft-row"><div class="faq-draft-copy"><span>DESTEK ÖNERİSİ</span><h3>'
                .self::e($draft->question).'</h3><p>'.nl2br(self::e($draft->answer),false).'</p>'
                .'<small>Kaynak · <a href="'.self::e($ticketHref).'">ticket #'.self::e($draft->ticketId->value()).'</a>'
                .' · '.self::e($draft->createdAt->format('Y-m-d H:i')).'</small></div>'
                .'<div class="faq-draft-actions"><form method="post" action="'.$action.'" class="search-form">'
                .self::csrf($csrfToken)
                .'<input type="hidden" name="action" value="apply">'
                .'<input type="hidden" name="draft_id" value="'.self::e($draft->draftId->value()).'">'
                .'<label><span>FAQ kategorisi</span><select name="category" required>'.$options.'</select></label>'
                .'<label><span>Slug</span><input name="slug" maxlength="160" pattern="[a-z0-9][a-z0-9-]{1,159}" required></label>'
                .'<div class="search-actions"><button class="fx-btn fx-btn--primary" type="submit">Taslağa dönüştür</button></div></form>'
                .'<form method="post" action="'.$action.'" class="faq-draft-reject">'.self::csrf($csrfToken)
                .'<input type="hidden" name="action" value="reject">'
                .'<input type="hidden" name="draft_id" value="'.self::e($draft->draftId->value()).'">'
                .'<button class="fx-btn" type="submit">Öneriyi reddet</button></form></div></article>';
        }

        $body.='</div></section></section>';
        return ProfileHtml::page('SSS taslakları',$body,$basePath,authenticated:true);
    }

    private static function csrf(string $token):string
    { return '<input type="hidden" name="_csrf" value="'.self::e($token).'">'; }
    private static function e(string $v):string { return ProfileHtml::escape($v); }
}
