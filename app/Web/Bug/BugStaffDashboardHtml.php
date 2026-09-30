<?php

declare(strict_types=1);

namespace Forwext\App\Web\Bug;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Bug\Report\BugReportStatus;
use Forwext\Core\Bug\Staff\BugStaffDashboard;
use Forwext\Core\Routing\BasePath;

final class BugStaffDashboardHtml
{
    /**
     * @param array<string,string> $usernames
     */
    public static function page(
        BugStaffDashboard $dashboard,
        BasePath $basePath,
        array $usernames,
        ?string $assigneeQuery,
    ): string {
        $s=$dashboard->summary;
        $stats='<div class="staff-dashboard-stats">'
            .self::stat('Toplam',(string)$s->total)
            .self::stat('Yeni',(string)$s->new)
            .self::stat('İncelemede',(string)$s->inReview)
            .self::stat('Çözüldü',(string)$s->resolved)
            .self::stat('Reddedildi',(string)$s->rejected)
            .self::stat('Duplicate',(string)$s->duplicate)
            .self::stat('Atanmamış',(string)$s->unassigned)
            .'</div>';

        $filter='<section class="surface-panel staff-filter-panel"><header><h2>Filtreler ve arama</h2>'
            .($dashboard->canExport?'<a class="fx-btn" href="'.self::e(self::exportPath($dashboard,$basePath,$assigneeQuery)).'">CSV dışa aktar</a>':'')
            .'</header><form method="get" action="'.self::e($basePath->prepend('/bugs/staff')).'" class="search-form staff-filter-form">'
            .'<label><span>Arama</span><input name="q" maxlength="200" value="'.self::e($dashboard->filter->text??'').'" placeholder="Başlık veya özet"></label>'
            .'<label><span>Durum</span><select name="status"><option value="">Tümü</option>';
        foreach(BugReportStatus::cases() as $status){
            $filter.='<option value="'.self::e($status->value).'"'.($dashboard->filter->status===$status?' selected':'').'>'
                .self::e($status->label()).'</option>';
        }
        $filter.='</select></label><label><span>Önem</span><select name="severity"><option value="">Tümü</option>';
        foreach(BugReportSeverity::cases() as $severity){
            $filter.='<option value="'.self::e($severity->value).'"'.($dashboard->filter->severity===$severity?' selected':'').'>'
                .self::e($severity->label()).'</option>';
        }
        $filter.='</select></label><label><span>Kategori</span><select name="category"><option value="">Tümü</option>';
        foreach($dashboard->categories as $category){
            $filter.='<option value="'.self::e($category->key).'"'
                .($dashboard->filter->categoryKey===$category->key?' selected':'').'>'.self::e($category->label).'</option>';
        }
        $filter.='</select></label><label><span>Atanan kullanıcı</span><input name="assignee" maxlength="80" value="'
            .self::e($assigneeQuery??'').'" placeholder="Kullanıcı adı veya unassigned"></label>'
            .'<div class="search-actions"><button type="submit">Uygula</button><a href="'
            .self::e($basePath->prepend('/bugs/staff')).'">Temizle</a></div></form></section>';

        $queue='';
        if($dashboard->reports===[]){
            $queue='<div class="surface-empty"><strong>Hata bildirimi bulunamadı.</strong><span>Bu filtrelerle eşleşen kayıt yok.</span></div>';
        }else{
            foreach($dashboard->reports as $report){
                $href=$basePath->prepend('/bugs/'.rawurlencode($report->reportId->value()));
                $assignee=$report->assignedUserId===null
                    ?'Atanmamış'
                    :($usernames[$report->assignedUserId->value()]??$report->assignedUserId->value());
                $reporter=$report->reporterUserId===null
                    ?'Silinmiş kullanıcı'
                    :($usernames[$report->reporterUserId->value()]??$report->reporterUserId->value());
                $queue.='<a class="staff-queue-row" href="'.self::e($href).'"><div class="staff-queue-main">'
                    .'<span class="staff-queue-meta">'.self::e($report->status->label()).' · '
                    .self::e($report->severity->label()).' · '.self::e($report->categoryKey).'</span>'
                    .'<strong>'.self::e($report->title).'</strong>'
                    .'<p>'.self::e(self::excerpt($report->summary)).'</p>'
                    .'<small>Bildiren · '.self::e($reporter).' · Atanan · '.self::e($assignee)
                    .' · Güncellendi · '.self::e($report->updatedAt->format('Y-m-d H:i')).'</small></div>'
                    .'<span aria-hidden="true">→</span></a>';
            }
        }

        $metricRows='';
        foreach($dashboard->categoryMetrics as $metric){
            $metricRows.='<tr><td>'.self::e($metric->categoryLabel).'</td><td>'.$metric->total.'</td><td>'
                .$metric->active.'</td><td>'.$metric->terminal.'</td><td>'.$metric->duplicates.'</td></tr>';
        }
        if($metricRows===''){
            $metricRows='<tr><td colspan="5" class="muted">Kategori analitiği bulunmuyor.</td></tr>';
        }

        $audit='';
        if($dashboard->canViewAudit){
            if($dashboard->audit===[]){
                $audit='<div class="surface-empty"><strong>Audit kaydı yok.</strong><span>Yeni işlemler burada görünecek.</span></div>';
            }else{
                $audit='<ul class="staff-audit-list">';
                foreach($dashboard->audit as $entry){
                    $actor=$usernames[$entry->actorUserId->value()]??$entry->actorUserId->value();
                    $audit.='<li><time>'.self::e($entry->occurredAt->format('Y-m-d H:i:s')).'</time><span>'
                        .self::e($entry->action).' · '.self::e($entry->targetType.':'.$entry->targetId)
                        .' · '.self::e($actor).' · request '.self::e($entry->requestId).'</span></li>';
                }
                $audit.='</ul>';
            }
        }

        $body='<section class="staff-dashboard discovery-page"><header class="surface-head staff-dashboard-head"><div>'
            .'<span class="forum-eyebrow">HATA YÖNETİMİ</span><h1>Hata yönetimi</h1>'
            .'<p>Staff kuyruğu, duplicate inceleme, atama, filtreleme ve raporlamayı tek yerde yönet.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/bugs')).'">Hata bildirimlerim</a></header>'
            .$stats.$filter
            .'<section class="surface-panel staff-panel"><header><h2>Hata kuyruğu</h2><span>'
            .count($dashboard->reports).'</span></header><div class="staff-queue-list">'.$queue.'</div></section>'
            .'<section class="surface-panel staff-panel"><header><h2>Kategori analitiği</h2><span>'
            .count($dashboard->categoryMetrics).'</span></header><div class="staff-table-wrap"><table>'
            .'<thead><tr><th>Kategori</th><th>Toplam</th><th>Aktif</th><th>Terminal</th><th>Duplicate</th></tr></thead><tbody>'
            .$metricRows.'</tbody></table></div></section>';

        if($dashboard->canViewAudit){
            $body.='<section class="surface-panel staff-panel"><header><h2>Bug audit</h2><span>'
                .count($dashboard->audit).'</span></header>'.$audit.'</section>';
        }

        $body.='</section>';
        return ProfileHtml::page('Hata yönetimi',$body,$basePath,authenticated:true);
    }

    private static function exportPath(BugStaffDashboard $dashboard,BasePath $basePath,?string $assigneeQuery):string
    {
        $query=array_filter([
            'q'=>$dashboard->filter->text,
            'status'=>$dashboard->filter->status?->value,
            'severity'=>$dashboard->filter->severity?->value,
            'category'=>$dashboard->filter->categoryKey,
            'assignee'=>$assigneeQuery,
        ],static fn(mixed $value):bool=>is_string($value)&&$value!=='');
        return $basePath->prepend('/bugs/staff/export.csv')
            .($query===[]?'':'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986));
    }

    private static function excerpt(string $value):string
    {
        $value=trim(preg_replace('/\s+/u',' ',$value)??$value);
        if(function_exists('mb_strlen')&&mb_strlen($value,'UTF-8')>220){
            return mb_substr($value,0,217,'UTF-8').'...';
        }
        return strlen($value)>220?substr($value,0,217).'...':$value;
    }

    private static function stat(string $label,string $value):string
    {
        return '<div class="staff-dashboard-stat"><strong>'.self::e($value).'</strong><span>'.self::e($label).'</span></div>';
    }

    private static function e(string $value):string
    {
        return ProfileHtml::escape($value);
    }
}
