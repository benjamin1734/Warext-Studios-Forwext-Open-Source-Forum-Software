<?php

declare(strict_types=1);

namespace Forwext\App\Web\Support;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Ticket\SupportTicket;

final class MyTicketsHtml
{
    /** @param list<SupportTicket> $tickets */
    public static function page(array $tickets, BasePath $basePath): string
    {
        $body='<section class="support-tickets discovery-page"><header class="surface-head support-tickets-head"><div>'
            .'<span class="forum-eyebrow">DESTEK</span><h1>Taleplerim</h1>'
            .'<p>Yalnız kendi hesabınla oluşturduğun destek talepleri listelenir.</p></div>'
            .'<a class="fx-btn fx-btn--primary" href="'.self::e($basePath->prepend('/support/new')).'">Yeni talep</a>'
            .'</header><section class="surface-panel support-ticket-panel"><div class="support-ticket-list">';
        if($tickets===[]){
            $body.='<div class="surface-empty">Henüz destek talebin yok.</div></div></section></section>';
            return ProfileHtml::page('Taleplerim',$body,$basePath,authenticated:true);
        }

        foreach($tickets as $ticket){
            $href=$basePath->prepend('/support/tickets/'.rawurlencode($ticket->ticketId->value()));
            $body.='<a class="support-ticket-row" href="'.self::e($href).'"><div>'
                .'<span class="support-ticket-meta">'.self::e($ticket->categoryKey).' · '.self::e($ticket->status->label())
                .' · '.self::e($ticket->priority->label()).'</span>'
                .'<strong>'.self::e($ticket->subject).'</strong>'
                .'<small>Güncellendi · '.self::e($ticket->updatedAt->format('Y-m-d H:i')).'</small></div>'
                .'<span aria-hidden="true">→</span></a>';
        }
        $body.='</div></section></section>';

        return ProfileHtml::page('Taleplerim',$body,$basePath,authenticated:true);
    }

    private static function e(string $value):string { return ProfileHtml::escape($value); }
}
