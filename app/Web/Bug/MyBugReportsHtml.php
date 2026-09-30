<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Bug\Report\BugReport;
use Forwext\Core\Routing\BasePath;

final class MyBugReportsHtml
{
    /**
     * @param list<BugReport> $reports
     * @param array<string,string> $categoryLabels
     */
    public static function page(array $reports,array $categoryLabels,BasePath $basePath): string
    {
        $body='<section class="bug-list-page discovery-page"><header class="surface-head bug-list-head"><div>'
            .'<span class="forum-eyebrow">HATA BİLDİRİMLERİ</span><h1>Hata Bildirimlerim</h1>'
            .'<p>Gönderdiğin hata kayıtlarını, durumlarını ve güncellemelerini takip et.</p></div>'
            .'<a class="fx-btn fx-btn--primary" href="'.self::e($basePath->prepend('/bugs/report')).'">Yeni hata bildir</a>'
            .'</header><section class="surface-panel bug-list-panel"><div class="bug-report-list">';

        if($reports===[]){
            $body.='<div class="surface-empty"><strong>Henüz hata bildirimin yok.</strong>'
                .'<span>Yeni bir kayıt oluşturduğunda burada görünecek.</span></div></div></section></section>';
            return ProfileHtml::page('Hata Bildirimlerim',$body,$basePath,authenticated:true);
        }

        foreach($reports as $report){
            $href=$basePath->prepend('/bugs/'.rawurlencode($report->reportId->value()));
            $category=$categoryLabels[$report->categoryKey]??$report->categoryKey;
            $body.='<a class="bug-report-row" href="'.self::e($href).'"><div class="bug-report-row-main">'
                .'<span class="bug-report-row-meta">'.self::e($category).' · '.self::e($report->status->label())
                .' · '.self::e($report->severity->label()).'</span><strong>'.self::e($report->title).'</strong>'
                .'<small>Oluşturuldu · '.self::e($report->createdAt->format('Y-m-d H:i'))
                .' · Güncellendi · '.self::e($report->updatedAt->format('Y-m-d H:i')).'</small></div>'
                .'<span class="bug-report-row-arrow" aria-hidden="true">→</span></a>';
        }
        $body.='</div></section></section>';

        return ProfileHtml::page('Hata Bildirimlerim',$body,$basePath,authenticated:true);
    }

    private static function e(string $value):string{return ProfileHtml::escape($value);}
}
