<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Faq\SupportBridge\FaqSupportRecommendation;
use Forwext\Core\Routing\BasePath;

final class FaqRecommendationHtml
{
    /** @param list<FaqSupportRecommendation> $recommendations */
    public static function section(
        array $recommendations,
        BasePath $basePath,
        string $title='İlgili SSS önerileri',
        string $empty='Bu bilgilerle eşleşen görünür bir SSS bulunamadı.',
    ):string {
        $html='<section class="surface-panel support-faq-recommendations"><header><div><h2>'.self::e($title).'</h2>'
            .'<p>Talep oluşturmadan önce bu cevaplardan biri sorunu çözebilir.</p></div><span>'
            .count($recommendations).'</span></header><div class="support-faq-list">';

        if($recommendations===[]){
            return $html.'<div class="surface-empty"><strong>Eşleşen SSS yok.</strong><span>'
                .self::e($empty).'</span></div></div></section>';
        }

        foreach($recommendations as $recommendation){
            $article=$recommendation->view->article;
            $href=$basePath->prepend('/faq/'.rawurlencode($article->language).'/'.rawurlencode($article->slug));
            $html.='<a class="support-faq-row" href="'.self::e($href).'"><div><span>'
                .self::e($recommendation->view->category->label).' · '.self::e($article->language)
                .'</span><strong>'.self::e($article->question).'</strong><small>Eşleşme puanı · '
                .self::e((string)$recommendation->score).'</small></div><span aria-hidden="true">→</span></a>';
        }

        return $html.'</div></section>';
    }

    private static function e(string $v):string { return ProfileHtml::escape($v); }
}
