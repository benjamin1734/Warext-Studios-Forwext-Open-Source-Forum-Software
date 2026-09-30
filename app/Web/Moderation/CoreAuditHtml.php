<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Routing\BasePath;
use JsonException;

final class CoreAuditHtml
{
    /** @param list<AuditEvent> $events */
    public static function page(
        array $events,
        BasePath $basePath,
        ?string $actorFilter = null,
        ?string $requestFilter = null,
    ): string {
        $rows='';
        foreach($events as $event){
            $rows.=self::event($event);
        }
        if($rows===''){
            $rows='<div class="surface-empty"><strong>Audit kaydı bulunamadı.</strong>'
                .'<span>Filtreyi değiştirerek tekrar deneyebilirsin.</span></div>';
        }

        $action=self::e($basePath->prepend('/moderation/audit'));
        $filters='<section class="surface-panel moderation-audit-filter"><form method="get" action="'.$action.'" class="search-form">'
            .'<label><span>Actor user id</span><input name="actor" maxlength="191" value="'.self::e($actorFilter??'').'"></label>'
            .'<label><span>Request id</span><input name="request_id" maxlength="100" value="'.self::e($requestFilter??'').'"></label>'
            .'<div class="search-actions"><button type="submit">Filtrele</button><a href="'.$action.'">Sıfırla</a></div>'
            .'</form></section>';

        $content='<section class="moderation-subpage discovery-page"><header class="surface-head moderation-subpage-head"><div>'
            .'<span class="forum-eyebrow">AUDIT</span><h1>Core Audit Stream</h1>'
            .'<p>Moderasyon ve yönetim eylemlerini actor, target, request-id ve redacted snapshotlarla incele.</p></div>'
            .'<a class="fx-btn" href="'.self::e($basePath->prepend('/moderation/oversight')).'">Bağımsız denetim</a></header>'
            .$filters
            .'<section class="surface-panel moderation-report-panel"><header><h2>Son olaylar</h2><span>'
            .count($events).'</span></header><div class="moderation-list">'.$rows.'</div></section></section>';

        return ProfileHtml::page('Core Audit Stream',$content,$basePath,authenticated:true);
    }

    private static function event(AuditEvent $event): string
    {
        return '<article class="moderation-audit-row"><div class="moderation-audit-row-head"><span class="moderation-row-type">'
            .self::e($event->scope->label()).'</span><time>'.self::e($event->occurredAt->format('Y-m-d H:i:s')).' UTC</time></div>'
            .'<h3>'.self::e($event->action->value()).'</h3>'
            .'<div class="moderation-row-meta">Actor · '.self::e($event->actorUserId->value())
            .' · Target · '.self::e($event->targetType.':'.$event->targetId).'</div>'
            .'<div class="moderation-row-meta">Request · '.self::e($event->requestId->value())
            .($event->reasonCode===null?'':' · Reason · '.self::e($event->reasonCode)).'</div>'
            .'<details class="moderation-audit-snapshots"><summary>Before / After</summary><div>'
            .'<section><strong>Before</strong><pre>'.self::e(self::json($event->before)).'</pre></section>'
            .'<section><strong>After</strong><pre>'.self::e(self::json($event->after)).'</pre></section>'
            .'</div></details></article>';
    }

    /** @param array<string|int,mixed> $value */
    private static function json(array $value): string
    {
        try{
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES,
            );
        }catch(JsonException){
            return '{"error":"snapshot-unavailable"}';
        }
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
