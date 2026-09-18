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
        $body='<section class="card settings"><h1>Taleplerim</h1>'
            .'<p><a href="'.self::e($basePath->prepend('/support/new')).'">Yeni destek talebi aç</a></p>'
            .'<p class="muted">Yalnız kendi hesabınızla oluşturduğunuz destek talepleri listelenir.</p></section>';

        $body.='<section class="card section"><h2>Destek geçmişi</h2>';
        if($tickets===[]){
            $body.='<div class="empty">Henüz destek talebiniz yok.</div></section>';
            return ProfileHtml::page('Taleplerim',$body,$basePath,authenticated:true);
        }

        foreach($tickets as $ticket){
            $href=$basePath->prepend('/support/tickets/'.rawurlencode($ticket->ticketId->value()));
            $body.='<article class="search-hit"><div class="search-hit-type">'
                .self::e($ticket->categoryKey).' · '.self::e($ticket->status->label())
                .' · '.self::e($ticket->priority->label()).'</div>'
                .'<h3><a href="'.self::e($href).'">'.self::e($ticket->subject).'</a></h3>'
                .'<p class="muted">Güncellendi: '.self::e($ticket->updatedAt->format('Y-m-d H:i')).'</p></article>';
        }
        $body.='</section>';

        return ProfileHtml::page('Taleplerim',$body,$basePath,authenticated:true);
    }

    private static function e(string $value):string { return ProfileHtml::escape($value); }
}
