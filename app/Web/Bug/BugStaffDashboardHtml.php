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
        $s = $dashboard->summary;
        $body = '<section class="card settings"><h1>Hata yönetimi</h1>'
            . '<p class="muted">Staff kuyruğu, duplicate inceleme, assignment, arama, filtreleme ve raporlama.</p>'
            . '<div class="profile-stats">'
            . self::stat('Toplam',(string)$s->total)
            . self::stat('Yeni',(string)$s->new)
            . self::stat('İncelemede',(string)$s->inReview)
            . self::stat('Çözüldü',(string)$s->resolved)
            . self::stat('Reddedildi',(string)$s->rejected)
            . self::stat('Duplicate',(string)$s->duplicate)
            . self::stat('Atanmamış',(string)$s->unassigned)
            . '</div></section>';

        $body .= '<section class="card section"><h2>Filtreler ve arama</h2>'
            . '<form method="get" action="' . self::e($basePath->prepend('/bugs/staff')) . '" class="search-form">'
            . '<label><span>Arama</span><input name="q" maxlength="200" value="'
            . self::e($dashboard->filter->text ?? '') . '" placeholder="Başlık veya özet"></label>'
            . '<label><span>Durum</span><select name="status"><option value="">Tümü</option>';
        foreach (BugReportStatus::cases() as $status) {
            $body .= '<option value="' . self::e($status->value) . '"'
                . ($dashboard->filter->status === $status ? ' selected' : '') . '>'
                . self::e($status->label()) . '</option>';
        }
        $body .= '</select></label><label><span>Önem</span><select name="severity"><option value="">Tümü</option>';
        foreach (BugReportSeverity::cases() as $severity) {
            $body .= '<option value="' . self::e($severity->value) . '"'
                . ($dashboard->filter->severity === $severity ? ' selected' : '') . '>'
                . self::e($severity->label()) . '</option>';
        }
        $body .= '</select></label><label><span>Kategori</span><select name="category"><option value="">Tümü</option>';
        foreach ($dashboard->categories as $category) {
            $body .= '<option value="' . self::e($category->key) . '"'
                . ($dashboard->filter->categoryKey === $category->key ? ' selected' : '') . '>'
                . self::e($category->label) . '</option>';
        }
        $body .= '</select></label>'
            . '<label><span>Atanan kullanıcı</span><input name="assignee" maxlength="80" value="'
            . self::e($assigneeQuery ?? '') . '" placeholder="Kullanıcı adı veya unassigned"></label>'
            . '<div class="search-actions"><button type="submit">Uygula</button>'
            . '<a href="' . self::e($basePath->prepend('/bugs/staff')) . '">Temizle</a></div></form>';

        if ($dashboard->canExport) {
            $body .= '<p><a href="' . self::e(self::exportPath($dashboard,$basePath,$assigneeQuery))
                . '">Bu filtreyi CSV olarak dışa aktar</a></p>';
        }
        $body .= '</section>';

        $body .= '<section class="card section"><h2>Hata kuyruğu</h2>';
        if ($dashboard->reports === []) {
            $body .= '<div class="empty">Filtreye uyan hata bildirimi yok.</div>';
        } else {
            foreach ($dashboard->reports as $report) {
                $href = $basePath->prepend('/bugs/' . rawurlencode($report->reportId->value()));
                $assignee = $report->assignedUserId === null
                    ? 'Atanmamış'
                    : ($usernames[$report->assignedUserId->value()] ?? $report->assignedUserId->value());
                $reporter = $report->reporterUserId === null
                    ? 'Silinmiş kullanıcı'
                    : ($usernames[$report->reporterUserId->value()] ?? $report->reporterUserId->value());
                $body .= '<article class="search-hit"><div class="search-hit-type">'
                    . self::e($report->status->label()) . ' · ' . self::e($report->severity->label())
                    . ' · ' . self::e($report->categoryKey) . '</div>'
                    . '<h3><a href="' . self::e($href) . '">' . self::e($report->title) . '</a></h3>'
                    . '<p>' . self::e(self::excerpt($report->summary)) . '</p>'
                    . '<p class="muted">Bildiren: ' . self::e($reporter)
                    . ' · Atanan: ' . self::e($assignee)
                    . ' · Güncellendi: ' . self::e($report->updatedAt->format('Y-m-d H:i')) . '</p></article>';
            }
        }
        $body .= '</section>';

        $body .= '<section class="card section"><h2>Kategori analitiği</h2>'
            . '<div class="table-wrap"><table><thead><tr><th>Kategori</th><th>Toplam</th>'
            . '<th>Aktif</th><th>Terminal</th><th>Duplicate</th></tr></thead><tbody>';
        foreach ($dashboard->categoryMetrics as $metric) {
            $body .= '<tr><td>' . self::e($metric->categoryLabel) . '</td>'
                . '<td>' . $metric->total . '</td><td>' . $metric->active . '</td>'
                . '<td>' . $metric->terminal . '</td><td>' . $metric->duplicates . '</td></tr>';
        }
        $body .= '</tbody></table></div></section>';

        if ($dashboard->canViewAudit) {
            $body .= '<section class="card section"><h2>Bug audit</h2>';
            if ($dashboard->audit === []) {
                $body .= '<div class="empty">Henüz bug audit kaydı yok.</div>';
            } else {
                $body .= '<ul>';
                foreach ($dashboard->audit as $entry) {
                    $actor = $usernames[$entry->actorUserId->value()] ?? $entry->actorUserId->value();
                    $body .= '<li>' . self::e($entry->occurredAt->format('Y-m-d H:i:s'))
                        . ' — ' . self::e($entry->action)
                        . ' — ' . self::e($entry->targetType . ':' . $entry->targetId)
                        . ' — ' . self::e($actor)
                        . ' — request ' . self::e($entry->requestId) . '</li>';
                }
                $body .= '</ul>';
            }
            $body .= '</section>';
        }

        return ProfileHtml::page('Hata yönetimi',$body,$basePath,authenticated:true);
    }

    private static function exportPath(BugStaffDashboard $dashboard,BasePath $basePath,?string $assigneeQuery):string
    {
        $query = array_filter([
            'q'=>$dashboard->filter->text,
            'status'=>$dashboard->filter->status?->value,
            'severity'=>$dashboard->filter->severity?->value,
            'category'=>$dashboard->filter->categoryKey,
            'assignee'=>$assigneeQuery,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');
        return $basePath->prepend('/bugs/staff/export.csv')
            . ($query === [] ? '' : '?' . http_build_query($query,'','&',PHP_QUERY_RFC3986));
    }

    private static function excerpt(string $value):string
    {
        $value = trim(preg_replace('/\s+/u',' ',$value) ?? $value);
        if (function_exists('mb_strlen') && mb_strlen($value,'UTF-8') > 220) {
            return mb_substr($value,0,217,'UTF-8') . '...';
        }
        return strlen($value) > 220 ? substr($value,0,217) . '...' : $value;
    }

    private static function stat(string $label,string $value):string
    {
        return '<div><strong>'.self::e($label).'</strong><span>'.self::e($value).'</span></div>';
    }

    private static function e(string $value):string
    {
        return ProfileHtml::escape($value);
    }
}
