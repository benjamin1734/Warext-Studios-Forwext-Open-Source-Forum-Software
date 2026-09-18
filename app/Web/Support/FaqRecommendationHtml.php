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
        $html='<section class="card section"><h2>'.self::e($title).'</h2>';
        if($recommendations===[]){
            return $html.'<p class="muted">'.self::e($empty).'</p></section>';
        }
        $html.='<p class="muted">Talep oluşturmadan önce bu cevaplardan biri sorununuzu çözebilir.</p>';
        foreach($recommendations as $recommendation){
            $article=$recommendation->view->article;
            $href=$basePath->prepend('/faq/'.rawurlencode($article->language).'/'.rawurlencode($article->slug));
            $html.='<article class="search-hit"><div class="search-hit-type">'
                .self::e($recommendation->view->category->label).' · '.self::e($article->language)
                .'</div><h3><a href="'.self::e($href).'">'.self::e($article->question).'</a></h3>'
                .'<p class="muted">Eşleşme puanı: '.self::e((string)$recommendation->score).'</p></article>';
        }
        return $html.'</section>';
    }

    private static function e(string $v):string { return ProfileHtml::escape($v); }
}
