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
        $body='<section class="card"><h1>Destekten SSS taslakları</h1>'
            .'<p class="muted">Yetkili public ticket cevaplarından önerilen taslakları inceleyin. Uygulanan taslaklar inactive/staff görünür başlar.</p>'
            .($updated?'<div class="notice success">Taslak güncellendi.</div>':'');
        if($drafts===[]){
            $body.='<div class="empty">Bekleyen SSS taslağı yok.</div></section>';
            return ProfileHtml::page('SSS taslakları',$body,$basePath,authenticated:true);
        }

        foreach($drafts as $draft){
            $options='';
            foreach($categories as $category){
                $selected=$draft->suggestedCategoryKey===$category->key?' selected':'';
                $options.='<option value="'.self::e($category->key).'"'.$selected.'>'
                    .self::e($category->label.' ('.$category->language.')').'</option>';
            }
            $ticketHref=$basePath->prepend('/support/tickets/'.rawurlencode($draft->ticketId->value()));
            $body.='<article class="card section"><h2>'.self::e($draft->question).'</h2>'
                .'<p>'.nl2br(self::e($draft->answer),false).'</p>'
                .'<p class="muted">Kaynak: <a href="'.self::e($ticketHref).'">ticket #'.self::e($draft->ticketId->value()).'</a>'
                .' · '.self::e($draft->createdAt->format('Y-m-d H:i')).'</p>'
                .'<form method="post" action="'.$action.'" class="search-form">'
                .self::csrf($csrfToken)
                .'<input type="hidden" name="action" value="apply">'
                .'<input type="hidden" name="draft_id" value="'.self::e($draft->draftId->value()).'">'
                .'<label><span>FAQ kategorisi</span><select name="category" required>'.$options.'</select></label>'
                .'<label><span>Slug</span><input name="slug" maxlength="160" pattern="[a-z0-9][a-z0-9-]{1,159}" required></label>'
                .'<div class="search-actions"><button type="submit">Inactive taslağa dönüştür</button></div></form>'
                .'<form method="post" action="'.$action.'">'.self::csrf($csrfToken)
                .'<input type="hidden" name="action" value="reject">'
                .'<input type="hidden" name="draft_id" value="'.self::e($draft->draftId->value()).'">'
                .'<button type="submit">Öneriyi reddet</button></form></article>';
        }
        $body.='</section>';
        return ProfileHtml::page('SSS taslakları',$body,$basePath,authenticated:true);
    }

    private static function csrf(string $token):string
    { return '<input type="hidden" name="_csrf" value="'.self::e($token).'">'; }
    private static function e(string $v):string { return ProfileHtml::escape($v); }
}
