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
        $rows = '';
        foreach ($events as $event) {
            $rows .= self::event($event);
        }
        if ($rows === '') {
            $rows = '<div class="empty">Audit kaydı bulunamadı.</div>';
        }

        $action = self::e($basePath->prepend('/moderation/audit'));
        $filters = '<form method="get" action="' . $action . '" class="search-form">'
            . '<label><span>Actor user id</span><input name="actor" maxlength="191" value="'
            . self::e($actorFilter ?? '') . '"></label>'
            . '<label><span>Request id</span><input name="request_id" maxlength="100" value="'
            . self::e($requestFilter ?? '') . '"></label>'
            . '<div class="search-actions"><button type="submit">Filtrele</button>'
            . '<a class="button" href="' . $action . '">Sıfırla</a></div></form>';

        $content = '<div class="card"><h1 style="margin:0">Core Audit Stream</h1>'
            . '<p class="muted">Moderasyon ve yönetim eylemleri actor, target, action, request-id ve redacted before/after '
            . 'snapshotlarıyla tek merkezi akışta tutulur.</p>'
            . '<p><a href="' . self::e($basePath->prepend('/moderation/oversight')) . '">Bağımsız Moderasyon Denetimi</a></p></div>'
            . '<section class="card section">' . $filters . '</section>'
            . '<section class="card section"><h2>Son olaylar</h2>' . $rows . '</section>';

        return ProfileHtml::page('Core Audit Stream', $content, $basePath, authenticated: true);
    }

    private static function event(AuditEvent $event): string
    {
        return '<article class="search-hit">'
            . '<span class="search-hit-type">' . self::e($event->scope->label()) . '</span>'
            . '<h3>' . self::e($event->action->value()) . '</h3>'
            . '<div class="muted">Actor: ' . self::e($event->actorUserId->value())
            . ' · Target: ' . self::e($event->targetType . ':' . $event->targetId) . '</div>'
            . '<div class="muted">Request: ' . self::e($event->requestId->value())
            . ' · ' . self::e($event->occurredAt->format('Y-m-d H:i:s')) . ' UTC</div>'
            . ($event->reasonCode === null ? '' : '<div class="muted">Reason: ' . self::e($event->reasonCode) . '</div>')
            . '<details><summary>Before / After</summary><div class="search-form">'
            . '<label class="search-wide"><span>Before</span><pre>' . self::e(self::json($event->before)) . '</pre></label>'
            . '<label class="search-wide"><span>After</span><pre>' . self::e(self::json($event->after)) . '</pre></label>'
            . '</div></details></article>';
    }

    /** @param array<string|int,mixed> $value */
    private static function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            return '{"error":"snapshot-unavailable"}';
        }
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
