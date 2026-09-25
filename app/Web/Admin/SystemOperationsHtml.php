<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Operations\SystemBackupEntry;
use Forwext\Core\Admin\Operations\SystemOperationsService;
use Forwext\Core\Admin\Operations\SystemOperationsSnapshot;
use Forwext\Core\Routing\BasePath;

final class SystemOperationsHtml
{
    public static function page(
        SystemOperationsSnapshot $snapshot,
        ?SystemBackupEntry $verifiedBackup,
        BasePath $basePath,
        string $csrf,
        ?string $notice,
        int $logLimit,
        string $section,
    ): string {
        $action = self::escape($basePath->prepend('/admin/system/operations'));
        $breadcrumbs = AdminBreadcrumbsHtml::render([
            ['label'=>'Admin','path'=>'/admin'],
            ['label'=>'Sistem Operasyonları','path'=>null],
        ], $basePath);

        $noticeHtml = $notice === null
            ? ''
            : '<div class="ops-notice">İşlem tamamlandı: <strong>' . self::escape(str_replace('_', ' ', $notice)) . '</strong></div>';
        $sectionOptions = '';
        foreach (['all'=>'Tüm bölümler','health'=>'Health / integrity','maintenance'=>'Maintenance','jobs'=>'Jobs / cron','backups'=>'Backups','logs'=>'Loglar','repairs'=>'Repair araçları'] as $key=>$label) {
            $sectionOptions .= '<option value="' . self::escape($key) . '"' . ($section === $key ? ' selected' : '') . '>'
                . self::escape($label) . '</option>';
        }
        $filter = '<form class="ops-filter" method="get" action="' . $action . '">'
            . '<label>Bölüm<select class="ops-select" name="section">' . $sectionOptions . '</select></label>'
            . '<label>Log kaydı<select class="ops-select" name="logs">'
            . self::option(50, $logLimit) . self::option(100, $logLimit) . self::option(250, $logLimit)
            . '</select></label><button class="ops-button" type="submit">Filtrele</button>'
            . '<a class="ops-button" href="' . $action . '">Filtreyi sıfırla</a></form>';
        $sections = '';
        if ($section === 'all' || $section === 'health') {
            $sections .= self::health($snapshot);
        }
        if ($section === 'all' || $section === 'maintenance') {
            $sections .= self::maintenance($snapshot, $action, $csrf);
        }
        if ($section === 'all' || $section === 'jobs') {
            $sections .= self::jobs($snapshot, $action, $csrf);
        }
        if ($section === 'all' || $section === 'backups') {
            $sections .= self::backups($snapshot, $verifiedBackup, $action, $csrf);
        }
        if ($section === 'all' || $section === 'logs') {
            $sections .= self::logs($snapshot, $action, $logLimit);
        }
        if ($section === 'all' || $section === 'repairs') {
            $sections .= self::repairs($snapshot, $action, $csrf);
        }

        return '<section class="ops"><style>'
            . '.ops{display:grid;gap:18px}.ops-hero,.ops-panel{border:1px solid var(--line);background:var(--panel);border-radius:14px;padding:18px}.ops-hero h1,.ops-panel h2,.ops-card h3{margin:0}'
            . '.ops-muted{color:var(--muted)}.ops-notice{border:1px solid var(--line);border-radius:10px;padding:11px 13px;background:var(--panel2)}'
            . '.ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.ops-card{border:1px solid var(--line);border-radius:12px;padding:14px;background:var(--panel2);display:grid;gap:8px}.ops-card p{margin:0}'
            . '.ops-table-wrap{overflow:auto}.ops-table{width:100%;border-collapse:collapse}.ops-table th,.ops-table td{text-align:left;padding:9px;border-bottom:1px solid var(--line);vertical-align:top}.ops-table th{white-space:nowrap}'
            . '.ops-badge{display:inline-flex;padding:3px 8px;border:1px solid var(--line);border-radius:999px;font-size:.84rem}.ops-actions{display:flex;flex-wrap:wrap;gap:8px}.ops-actions form{margin:0}.ops-button{border:1px solid var(--line);background:var(--panel2);color:var(--text);border-radius:9px;padding:8px 11px;cursor:pointer;text-decoration:none}.ops-danger{font-weight:700}.ops-input,.ops-select{border:1px solid var(--line);background:var(--panel2);color:var(--text);border-radius:9px;padding:8px 10px;max-width:100%}'
            . '.ops-filter{display:grid;grid-template-columns:minmax(180px,1fr) minmax(150px,220px) auto auto;gap:8px;align-items:end}.ops-filter label{display:grid;gap:5px}'
            . '.ops-form{display:grid;gap:9px;max-width:700px}.ops-code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.86rem;overflow-wrap:anywhere}.ops-log{display:grid;gap:7px;padding:10px 0;border-bottom:1px solid var(--line)}.ops-log:last-child{border-bottom:0}.ops-log pre{white-space:pre-wrap;overflow-wrap:anywhere;margin:0;background:var(--panel2);padding:9px;border-radius:8px}'
            . AdminUxQualityHtml::css()
            . '@media(max-width:700px){.ops-actions,.ops-filter{display:grid;grid-template-columns:1fr}.ops-actions form,.ops-button{width:100%}.ops-table{min-width:700px}}'
            . '</style>'
            . $breadcrumbs
            . AdminUxQualityHtml::guidance(
                'Health, jobs, backup, log ve repair alanını bölüm filtresiyle daralt; yalnız yetkili olduğun bölümler render edilir.',
                'Tanılama salt-okunur başlar; otomatik repair çalışmaz ve minimum cPanel profili ayrı capability olarak görünür.',
                'Health/integrity, job metadata, backup verify ve redacted log görünümü işlem öncesi doğrulama sağlar.',
                'Destructive işlemler typed confirmation ister; backup silme ve cache temizleme audit edilir, maintenance environment override varken ACP yazamaz.',
            )
            . '<header class="ops-hero"><h1>Sistem Operasyon Merkezi</h1><p class="ops-muted">Health, capabilities, loglar, queue/cron, integrity, backup, maintenance ve güvenli repair araçları. Her bölüm backend permission ile ayrı korunur.</p></header>'
            . $noticeHtml
            . $filter
            . $sections
            . '</section>';
    }

    private static function health(SystemOperationsSnapshot $snapshot): string
    {
        if (!self::allowed($snapshot, SystemOperationsService::HEALTH_PERMISSION)) {
            return '';
        }

        $checks = '';
        if ($snapshot->health !== null) {
            foreach ($snapshot->health->checks as $check) {
                $details = $check->details === [] ? '' : '<small class="ops-code">'
                    . self::escape((string) json_encode($check->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
                    . '</small>';
                $checks .= '<article class="ops-card"><span class="ops-badge">'
                    . self::escape($check->status->value) . '</span><h3>' . self::escape($check->name)
                    . '</h3><p>' . self::escape($check->message) . '</p>' . $details . '</article>';
            }
        }

        $capabilities = '';
        foreach ($snapshot->capabilities as $capability) {
            $capabilities .= '<tr><td class="ops-code">' . self::escape($capability->name) . '</td><td>'
                . ($capability->available ? 'Evet' : 'Hayır') . '</td><td>'
                . ($capability->requiredForMinimumProfile ? 'Zorunlu' : 'Opsiyonel') . '</td><td>'
                . self::escape($capability->detail ?? '—') . '</td></tr>';
        }

        $integrity = '';
        if ($snapshot->integrity !== null) {
            $i = $snapshot->integrity;
            $integrity = '<div class="ops-grid"><article class="ops-card"><span class="ops-badge">'
                . ($i->healthy ? 'healthy' : 'attention') . '</span><h3>Migration integrity</h3>'
                . '<p>' . $i->appliedCoreMigrations . ' / ' . $i->registeredCoreMigrations . ' core migration applied.</p>'
                . '<small>Missing: ' . count($i->missingMigrations)
                . ' · Failed: ' . count($i->failedMigrations)
                . ' · Running: ' . count($i->runningMigrations)
                . ' · Unknown: ' . count($i->unknownCoreMigrations)
                . ' · Non-InnoDB: ' . $i->nonInnoDbTables . '</small></article></div>';
        }

        return '<section class="ops-panel"><h2>Health ve integrity</h2><p class="ops-muted">Read-only tanılama; hiçbir repair işlemi otomatik çalıştırılmaz.</p>'
            . '<div class="ops-grid">' . $checks . '</div>' . $integrity
            . '<h3>Runtime capabilities</h3><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Capability</th><th>Var</th><th>Profil</th><th>Detay</th></tr></thead><tbody>'
            . $capabilities . '</tbody></table></div></section>';
    }

    private static function maintenance(SystemOperationsSnapshot $snapshot, string $action, string $csrf): string
    {
        if (!self::allowed($snapshot, SystemOperationsService::MAINTENANCE_PERMISSION)) {
            return '';
        }

        $enabled = $snapshot->maintenanceEnabled === true;
        $disabled = $snapshot->maintenanceEnvironmentOverride ? ' disabled' : '';
        $warning = $snapshot->maintenanceEnvironmentOverride
            ? '<p class="ops-muted">Maintenance değeri environment override ile yönetiliyor; ACP değişikliği kapalı.</p>'
            : '';

        return '<section class="ops-panel"><h2>Maintenance</h2><p>Durum: <strong>'
            . ($enabled ? 'Açık' : 'Kapalı') . '</strong></p>' . $warning
            . '<form class="ops-form" method="post" action="' . $action . '">'
            . self::csrf($csrf)
            . '<input type="hidden" name="action" value="set_maintenance">'
            . '<label>Yeni durum <select class="ops-select" name="enabled"' . $disabled . '>'
            . '<option value="0"' . (!$enabled ? ' selected' : '') . '>Kapalı</option>'
            . '<option value="1"' . ($enabled ? ' selected' : '') . '>Açık</option></select></label>'
            . '<label>Onay için <code>MAINTENANCE</code> yaz <input class="ops-input" name="confirm" autocomplete="off"' . $disabled . '></label>'
            . '<button class="ops-button" type="submit"' . $disabled . '>Maintenance durumunu değiştir</button></form></section>';
    }

    private static function jobs(SystemOperationsSnapshot $snapshot, string $action, string $csrf): string
    {
        if (!self::allowed($snapshot, SystemOperationsService::JOB_PERMISSION)) {
            return '';
        }

        $queues = '';
        foreach ($snapshot->queues as $queue) {
            $queues .= '<tr><td class="ops-code">' . self::escape($queue['queue']) . '</td><td>'
                . $queue['pending'] . '</td><td>' . $queue['reserved'] . '</td><td>' . $queue['failed'] . '</td></tr>';
        }

        $tasks = '';
        foreach ($snapshot->scheduledTasks as $task) {
            $tasks .= '<tr><td class="ops-code">' . self::escape($task->name) . '</td><td class="ops-code">'
                . self::escape($task->schedule->value()) . '</td><td>' . self::escape($task->queue->value())
                . '</td><td class="ops-code">' . self::escape($task->jobType) . '</td><td>'
                . '<form method="post" action="' . $action . '">' . self::csrf($csrf)
                . '<input type="hidden" name="action" value="run_task"><input type="hidden" name="task_name" value="'
                . self::escape($task->name) . '"><button class="ops-button" type="submit">Şimdi queue’ya ekle</button></form></td></tr>';
        }

        $failed = '';
        foreach ($snapshot->failedJobs as $job) {
            $failed .= '<tr><td class="ops-code">' . self::escape($job->jobId) . '</td><td>'
                . self::escape($job->queueName) . '</td><td class="ops-code">' . self::escape($job->jobType)
                . '</td><td>' . $job->attempts . '/' . $job->maxAttempts . '</td><td>'
                . self::escape($job->failureCode) . '</td><td>' . self::escape($job->failedAt->format('Y-m-d H:i:s')) . ' UTC</td><td>'
                . '<div class="ops-actions"><form method="post" action="' . $action . '">' . self::csrf($csrf)
                . '<input type="hidden" name="action" value="retry_failed_job"><input type="hidden" name="job_id" value="'
                . self::escape($job->jobId) . '"><button class="ops-button" type="submit">Retry</button></form>'
                . '<form method="post" action="' . $action . '">' . self::csrf($csrf)
                . '<input type="hidden" name="action" value="delete_failed_job"><input type="hidden" name="job_id" value="'
                . self::escape($job->jobId) . '"><input class="ops-input" name="confirm" placeholder="Job ID ile onayla" autocomplete="off">'
                . '<button class="ops-button ops-danger" type="submit">Sil</button></form></div></td></tr>';
        }
        if ($failed === '') {
            $failed = '<tr><td colspan="7" class="ops-muted">Failed job yok.</td></tr>';
        }

        return '<section class="ops-panel"><h2>Queue, failed jobs ve cron</h2><p class="ops-muted">Job payloadları ACP’ye taşınmaz. Manuel cron çalıştırma doğrudan handler çağırmaz; kayıtlı job normal queue’ya eklenir.</p>'
            . '<h3>Queue durumu</h3><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Queue</th><th>Pending</th><th>Reserved</th><th>Failed</th></tr></thead><tbody>' . $queues . '</tbody></table></div>'
            . '<h3>Scheduled tasks</h3><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Task</th><th>Cron UTC</th><th>Queue</th><th>Job type</th><th></th></tr></thead><tbody>' . $tasks . '</tbody></table></div>'
            . '<h3>Failed jobs</h3><div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>ID</th><th>Queue</th><th>Type</th><th>Attempts</th><th>Failure</th><th>Time</th><th>Actions</th></tr></thead><tbody>' . $failed . '</tbody></table></div></section>';
    }

    private static function backups(
        SystemOperationsSnapshot $snapshot,
        ?SystemBackupEntry $verified,
        string $action,
        string $csrf,
    ): string {
        if (!self::allowed($snapshot, SystemOperationsService::BACKUP_PERMISSION)) {
            return '';
        }

        $verify = '';
        if ($verified !== null) {
            $verify = '<div class="ops-notice"><strong>Backup doğrulaması:</strong> '
                . self::escape($verified->name) . ' · ' . ($verified->verified ? 'geçerli' : 'geçersiz')
                . ' · tables=' . ($verified->tableCount ?? 0) . ' · rows=' . ($verified->rowCount ?? 0)
                . ' · sha256=<span class="ops-code">' . self::escape($verified->sha256 ?? '—') . '</span></div>';
        }

        $rows = '';
        foreach ($snapshot->backups as $backup) {
            $verifyUrl = $action . '?verify=' . rawurlencode($backup->name);
            $rows .= '<tr><td class="ops-code">' . self::escape($backup->name) . '</td><td>'
                . number_format($backup->sizeBytes) . '</td><td>' . self::escape($backup->modifiedAt->format('Y-m-d H:i:s')) . ' UTC</td><td>'
                . '<div class="ops-actions"><a class="ops-button" href="' . self::escape($verifyUrl) . '">Verify</a>'
                . '<form method="post" action="' . $action . '">' . self::csrf($csrf)
                . '<input type="hidden" name="action" value="delete_backup"><input type="hidden" name="backup_name" value="'
                . self::escape($backup->name) . '"><input class="ops-input" name="confirm" placeholder="Dosya adı ile onayla" autocomplete="off">'
                . '<button class="ops-button ops-danger" type="submit">Sil</button></form></div></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="4" class="ops-muted">Henüz backup yok.</td></tr>';
        }

        return '<section class="ops-panel"><h2>Backups</h2><p class="ops-muted">Backup dosyaları web kökü dışında tutulur; schema + tüm Forwext tablo satırları byte-safe logical formatta saklanır. ACP download endpoint’i yoktur.</p>'
            . $verify
            . '<form method="post" action="' . $action . '">' . self::csrf($csrf)
            . '<input type="hidden" name="action" value="create_backup"><button class="ops-button" type="submit">Yeni logical backup oluştur</button></form>'
            . '<div class="ops-table-wrap"><table class="ops-table"><thead><tr><th>Dosya</th><th>Bytes</th><th>Tarih</th><th>Actions</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section>';
    }

    private static function logs(SystemOperationsSnapshot $snapshot, string $action, int $logLimit): string
    {
        if (!self::allowed($snapshot, SystemOperationsService::LOG_PERMISSION)) {
            return '';
        }

        $items = '';
        foreach ($snapshot->logs as $entry) {
            $context = $entry->context === []
                ? ''
                : '<pre>' . self::escape((string) json_encode($entry->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
            $items .= '<article class="ops-log"><div><span class="ops-badge">' . self::escape($entry->level)
                . '</span> <span class="ops-code">' . self::escape($entry->timestamp) . '</span></div><strong>'
                . self::escape($entry->message) . '</strong>' . $context . '</article>';
        }
        if ($items === '') {
            $items = '<p class="ops-muted">Okunabilir structured log kaydı yok.</p>';
        }

        return '<section class="ops-panel"><h2>Structured logs</h2><p class="ops-muted">Son en fazla 2 MiB dosya penceresi okunur; secret/token/password benzeri context alanları ikinci kez redakte edilir.</p>'
            . '<form method="get" action="' . $action . '"><input type="hidden" name="section" value="logs"><label>Gösterilecek kayıt <select class="ops-select" name="logs">'
            . self::option(50, $logLimit) . self::option(100, $logLimit) . self::option(250, $logLimit)
            . '</select></label> <button class="ops-button" type="submit">Yenile</button></form>' . $items . '</section>';
    }

    private static function repairs(SystemOperationsSnapshot $snapshot, string $action, string $csrf): string
    {
        if (!self::allowed($snapshot, SystemOperationsService::REPAIR_PERMISSION)) {
            return '';
        }

        return '<section class="ops-panel"><h2>Maintenance ve repair tools</h2><p class="ops-muted">Araçlar bounded çalışır ve audit edilir. Otomatik “her şeyi düzelt” eylemi yoktur.</p>'
            . '<div class="ops-grid"><article class="ops-card"><h3>Scheduler claim cleanup</h3>'
            . '<form class="ops-form" method="post" action="' . $action . '">' . self::csrf($csrf)
            . '<input type="hidden" name="action" value="prune_scheduler_claims"><label>Bu günden eski claimleri temizle <input class="ops-input" type="number" min="1" max="3650" name="older_than_days" value="14"></label>'
            . '<button class="ops-button" type="submit">Stale claimleri temizle</button></form></article>'
            . '<article class="ops-card"><h3>Cache cleanup</h3><p>Cache kök dizini silinmez; yalnız güvenli içerikleri temizlenir ve symlink takip edilmez.</p>'
            . '<form class="ops-form" method="post" action="' . $action . '">' . self::csrf($csrf)
            . '<input type="hidden" name="action" value="clear_cache"><label>Onay için <code>CLEAR CACHE</code> yaz <input class="ops-input" name="confirm" autocomplete="off"></label>'
            . '<button class="ops-button ops-danger" type="submit">Cache temizle</button></form></article></div></section>';
    }

    private static function allowed(SystemOperationsSnapshot $snapshot, string $permission): bool
    {
        return $snapshot->permissions[$permission] ?? false;
    }

    private static function csrf(string $csrf): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::escape($csrf) . '">';
    }

    private static function option(int $value, int $selected): string
    {
        return '<option value="' . $value . '"' . ($value === $selected ? ' selected' : '') . '>' . $value . '</option>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
