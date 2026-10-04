<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Scheduler\ScheduledTask;

final class AdminCronHtml
{
    /** @param list<ScheduledTask> $tasks */
    public static function page(
        array $tasks,
        BasePath $basePath,
        string $csrf,
        string $query,
        DateTimeImmutable $now,
        ?string $updated = null,
    ): string {
        $action = self::e($basePath->prepend('/admin/system/cron'));
        $operations = self::e($basePath->prepend('/admin/system/operations?section=jobs'));
        $notice = $updated === 'run'
            ? '<div class="ops-notice">Görev normal queue akışına eklendi.</div>'
            : '';

        $filtered = [];
        $needle = mb_strtolower(trim($query), 'UTF-8');
        foreach ($tasks as $task) {
            $haystack = mb_strtolower(
                $task->name . "\n" . $task->jobType . "\n" . $task->queue->value() . "\n" . $task->schedule->value(),
                'UTF-8',
            );
            if ($needle === '' || str_contains($haystack, $needle)) {
                $filtered[] = $task;
            }
        }

        usort($filtered, static function (ScheduledTask $left, ScheduledTask $right) use ($now): int {
            $leftNext = $left->schedule->nextRunAfter($now);
            $rightNext = $right->schedule->nextRunAfter($now);
            return [$leftNext?->getTimestamp() ?? PHP_INT_MAX, $left->name]
                <=> [$rightNext?->getTimestamp() ?? PHP_INT_MAX, $right->name];
        });

        $rows = '';
        foreach ($filtered as $task) {
            $next = $task->schedule->nextRunAfter($now);
            $rows .= '<tr><td><strong>' . self::e($task->name) . '</strong><small>'
                . self::e($task->jobType) . '</small></td><td class="ops-code">'
                . self::e($task->schedule->value()) . '</td><td>' . self::e($task->queue->value())
                . '</td><td>' . ($next === null ? '—' : self::e($next->format('Y-m-d H:i')) . ' UTC') . '</td><td>'
                . '<form method="post" action="' . $action . '"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="run"><input type="hidden" name="task_name" value="'
                . self::e($task->name) . '"><button class="acp-button" type="submit">Şimdi çalıştır</button></form></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="5" class="ops-muted">Eşleşen cron görevi bulunamadı.</td></tr>';
        }

        return '<section class="cron-admin">'
            . '<header class="log-explorer-head"><div><h1>Cron Entries</h1>'
            . '<p>Kayıtlı scheduler görevlerini, UTC cron ifadelerini ve sonraki çalışma zamanlarını yönet.</p></div>'
            . '<a class="acp-button" href="' . $operations . '">Queue ve failed jobs</a></header>'
            . $notice
            . AdminUxQualityHtml::guidance(
                'Kayıtlı first-party scheduler görevlerini incele ve gerektiğinde normal queue üzerinden manuel tetikle.',
                'Görev tanımları kod/modül sahipliğindedir; ACP bunları keyfi olarak silmez veya handlerı doğrudan çağırmaz.',
                'Sonraki çalışma zamanı UTC cron ifadesinden sunucu tarafında hesaplanır.',
                'Yanlış manuel tetiklemede görev normal queue retry/failure kurallarına tabi kalır.',
            )
            . '<form class="cron-filter" method="get" action="' . $action . '">'
            . '<label><span>Filtre</span><input type="search" name="q" maxlength="120" value="' . self::e($query)
            . '" placeholder="Task, job type, queue veya cron..."></label>'
            . '<button class="acp-button primary" type="submit">Filtrele</button>'
            . '<a class="acp-button" href="' . $action . '">Sıfırla</a></form>'
            . '<section class="log-table-panel"><header><h2>Scheduled tasks</h2><span>'
            . count($filtered) . ' / ' . count($tasks) . '</span></header>'
            . '<div class="ops-table-wrap"><table class="ops-table cron-table"><thead><tr>'
            . '<th>Task</th><th>Cron (UTC)</th><th>Queue</th><th>Next run</th><th></th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section></section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
