<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Reporting\SupportStaffDashboard;

final class SupportStaffDashboardHtml
{
    public static function page(SupportStaffDashboard $dashboard,BasePath $basePath):string
    {
        $s=$dashboard->summary;
        $body='<section class="card settings"><h1>Destek paneli</h1>'
            .'<div class="profile-stats">'
            .self::stat('Toplam',(string)$s->total)
            .self::stat('Aktif',(string)$s->active)
            .self::stat('Çözüldü',(string)$s->resolved)
            .self::stat('Kapalı',(string)$s->closed)
            .self::stat('İlk yanıt SLA ihlali',(string)$s->firstResponseBreaches)
            .self::stat('Çözüm SLA ihlali',(string)$s->resolutionBreaches)
            .self::stat('Ort. ilk yanıt',self::duration($s->averageFirstResponseSeconds))
            .self::stat('Ort. çözüm',self::duration($s->averageResolutionSeconds))
            .'</div></section>';

        $body.='<section class="card section"><h2>Aktif kuyruk</h2>';
        if($dashboard->queue===[]){
            $body.='<div class="empty">Aktif destek talebi yok.</div>';
        }else{
            foreach($dashboard->queue as $ticket){
                $href=$basePath->prepend('/support/tickets/'.rawurlencode($ticket->ticketId->value()));
                $firstBreach=$ticket->sla->firstResponseBreached(new \DateTimeImmutable('now',new \DateTimeZone('UTC')));
                $resolutionBreach=$ticket->sla->resolutionBreached(new \DateTimeImmutable('now',new \DateTimeZone('UTC')));
                $body.='<article class="search-hit"><div class="search-hit-type">'
                    .self::e($ticket->priority->label()).' · '.self::e($ticket->status->label())
                    .($firstBreach?' · İlk yanıt SLA ihlali':'')
                    .($resolutionBreach?' · Çözüm SLA ihlali':'')
                    .'</div><h3><a href="'.self::e($href).'">'.self::e($ticket->subject).'</a></h3>'
                    .'<p class="muted">'.self::e($ticket->categoryKey).' · '.self::e($ticket->createdAt->format('Y-m-d H:i')).'</p></article>';
            }
        }
        $body.='</section>';

        $body.='<section class="card section"><h2>Kategori istatistikleri</h2><div class="table-wrap"><table>'
            .'<thead><tr><th>Kategori</th><th>Toplam</th><th>Aktif</th><th>Çözülen/Kapalı</th><th>İlk yanıt ihlali</th><th>Çözüm ihlali</th><th>Ort. ilk yanıt</th><th>Ort. çözüm</th></tr></thead><tbody>';
        foreach($dashboard->categories as $metric){
            $body.='<tr><td>'.self::e($metric->categoryLabel).'</td><td>'.$metric->total.'</td><td>'.$metric->active.'</td>'
                .'<td>'.$metric->resolvedOrClosed.'</td><td>'.$metric->firstResponseBreaches.'</td><td>'.$metric->resolutionBreaches.'</td>'
                .'<td>'.self::e(self::duration($metric->averageFirstResponseSeconds)).'</td>'
                .'<td>'.self::e(self::duration($metric->averageResolutionSeconds)).'</td></tr>';
        }
        $body.='</tbody></table></div></section>';

        if($dashboard->audit!==[]){
            $body.='<section class="card section"><h2>Destek audit</h2><ul>';
            foreach($dashboard->audit as $entry){
                $body.='<li>'.self::e($entry->occurredAt->format('Y-m-d H:i:s')).' — '
                    .self::e($entry->action).' — '.self::e($entry->targetType.':'.$entry->targetId)
                    .' — request '.self::e($entry->requestId).'</li>';
            }
            $body.='</ul></section>';
        }

        return ProfileHtml::page('Destek paneli',$body,$basePath,authenticated:true);
    }

    private static function stat(string $label,string $value):string
    { return '<div><strong>'.self::e($label).'</strong><span>'.self::e($value).'</span></div>'; }

    private static function duration(?float $seconds):string
    {
        if($seconds===null) return '—';
        $seconds=max(0,(int)round($seconds));
        if($seconds>=86400) return number_format($seconds/86400,1).' gün';
        if($seconds>=3600) return number_format($seconds/3600,1).' saat';
        if($seconds>=60) return number_format($seconds/60,1).' dk';
        return $seconds.' sn';
    }

    private static function e(string $value):string { return ProfileHtml::escape($value); }
}
