<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\Core\Admin\Community\AdminCommunitySection;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysis;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\User;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Routing\BasePath;

final class AdminCommunityHtml
{
    /** @param array<string,mixed> $snapshot */
    public static function page(
        AdminCommunitySection $section,
        array $snapshot,
        BasePath $basePath,
        string $csrf,
        EntityId $actor,
    ): string {
        $tabs = '';
        foreach (AdminCommunitySection::cases() as $tab) {
            $tabs .= '<a class="ac-tab" href="' . self::e($basePath->prepend($tab->path())) . '"'
                . ($tab === $section ? ' aria-current="page"' : '') . '>' . self::e($tab->label()) . '</a>';
        }

        $breadcrumbs = AdminBreadcrumbsHtml::render([
            ['label'=>'Admin','path'=>'/admin'],
            ['label'=>$section->label(),'path'=>null],
        ], $basePath);

        $body = match ($section) {
            AdminCommunitySection::Users => self::users(
                $snapshot['users'],
                $snapshot['access'],
                $basePath,
                $csrf,
                $actor,
            ),
            AdminCommunitySection::Access => self::access($snapshot['access'], $basePath, $csrf),
            AdminCommunitySection::Forums => self::forums($snapshot['forums'], $basePath, $csrf),
            AdminCommunitySection::Content => self::content($snapshot['operations'], $basePath),
            AdminCommunitySection::Moderation => self::moderation($snapshot['operations'], $basePath),
        };

        return '<section class="ac-community">'
            . $breadcrumbs
            . self::qualityGuidance($section)
            . '<nav class="ac-tabs" aria-label="ACP yönetim bölümleri">' . $tabs . '</nav>'
            . $body
            . '</section>';
    }

    /** @param array<string,mixed> $usersSnapshot @param array<string,mixed> $accessSnapshot */
    private static function users(
        array $usersSnapshot,
        array $accessSnapshot,
        BasePath $basePath,
        string $csrf,
        EntityId $actor,
    ): string {
        $rows = $usersSnapshot['users'];
        $selected = $usersSnapshot['selected'];
        $history = $usersSnapshot['selected_history'];
        $assignment = $usersSnapshot['access'];
        $query = trim((string) ($usersSnapshot['search'] ?? ''));
        $groups = $accessSnapshot['groups'];
        $roles = $accessSnapshot['roles'];
        $action = self::e($basePath->prepend('/admin/users'));

        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[(string) $group['group_id']] = (string) $group['name'];
        }
        $roleNames = [];
        foreach ($roles as $role) {
            $roleNames[(string) $role['role_id']] = (string) $role['name'];
        }

        $table = '';
        foreach ($rows as $row) {
            $url = $basePath->prepend('/admin/users?user=' . rawurlencode((string) $row['user_id'])
                . ($query !== '' ? '&q=' . rawurlencode($query) : ''));
            $table .= '<tr><td><a href="' . self::e($url) . '"><strong>' . self::e((string) $row['username'])
                . '</strong></a><small class="ac-user-id">' . self::e((string) $row['user_id']) . '</small></td>'
                . '<td><span class="ac-user-email">' . self::e((string) $row['email']) . '</span></td>'
                . '<td><span class="ac-badge ac-user-status" data-status="' . self::e((string) $row['status']) . '">'
                . self::e((string) $row['status']) . '</span></td>'
                . '<td><span>' . self::e((string) $row['locale']) . '</span><small class="ac-user-subline">'
                . self::e((string) $row['timezone']) . '</small></td>'
                . '<td><span>' . self::e((string) $row['created_at_utc']) . '</span><small class="ac-user-subline">Güncellendi '
                . self::e((string) $row['updated_at_utc']) . '</small></td></tr>';
        }

        $detail = '<div class="ac-user-empty"><strong>Kullanıcı seçilmedi.</strong><span>Liste üzerinden bir kullanıcı seçerek hesap, access ve geçmiş ayrıntılarını aç.</span></div>';
        if ($selected instanceof User) {
            $self = $selected->id()->equals($actor);
            $primaryOptions = '<option value="">Atanmamış</option>';
            foreach ($groups as $group) {
                $id = (string) $group['group_id'];
                $primaryOptions .= '<option value="' . self::e($id) . '"'
                    . (($assignment['primary'] ?? null) === $id ? ' selected' : '') . '>'
                    . self::e((string) $group['name']) . '</option>';
            }

            $secondaryOptions = '';
            foreach ($groups as $group) {
                $id = (string) $group['group_id'];
                $secondaryOptions .= '<option value="' . self::e($id) . '"'
                    . (in_array($id, $assignment['secondary'], true) ? ' selected' : '') . '>'
                    . self::e((string) $group['name']) . '</option>';
            }

            $roleOptions = '';
            foreach ($roles as $role) {
                $id = (string) $role['role_id'];
                $roleOptions .= '<option value="' . self::e($id) . '"'
                    . (in_array($id, $assignment['roles'], true) ? ' selected' : '') . '>'
                    . self::e((string) $role['name']) . ' · ' . self::e((string) $role['kind']) . '</option>';
            }

            $statusOptions = '';
            foreach ([UserStatus::Active, UserStatus::PendingApproval, UserStatus::Deactivated, UserStatus::DeletionPending] as $status) {
                $statusOptions .= '<option value="' . self::e($status->value) . '"'
                    . ($selected->status() === $status ? ' selected' : '') . '>' . self::e($status->value) . '</option>';
            }

            $secondaryLabels = [];
            foreach ($assignment['secondary'] as $id) {
                $secondaryLabels[] = $groupNames[$id] ?? $id;
            }
            $roleLabels = [];
            foreach ($assignment['roles'] as $id) {
                $roleLabels[] = $roleNames[$id] ?? $id;
            }
            $primaryLabel = ($assignment['primary'] ?? null) === null
                ? 'Atanmamış'
                : ($groupNames[$assignment['primary']] ?? $assignment['primary']);

            $historyRows = '';
            foreach ($history as $entry) {
                $historyRows .= '<tr><td><strong>' . self::e($entry->eventType) . '</strong></td><td>'
                    . self::e(implode(', ', $entry->changedFields)) . '</td><td>'
                    . self::e($entry->occurredAt->format('Y-m-d H:i:s')) . '</td><td>'
                    . self::e($entry->reasonCode ?? '—') . '</td></tr>';
            }

            $detail = '<div class="ac-user-detail">'
                . '<section class="ac-panel ac-user-summary"><div class="ac-user-summary-head"><div><span class="ac-muted">Seçili hesap</span><h2>'
                . self::e($selected->username()->display()) . '</h2><p>' . self::e($selected->email()->value()) . '</p></div>'
                . '<span class="ac-badge ac-user-status" data-status="' . self::e($selected->status()->value) . '">'
                . self::e($selected->status()->value) . '</span></div>'
                . '<div class="ac-user-facts">'
                . self::userFact('Kullanıcı ID', $selected->id()->value())
                . self::userFact('Locale', $selected->locale()->value())
                . self::userFact('Timezone', $selected->timezone()->value())
                . self::userFact('Oluşturuldu', $selected->createdAt()->format('Y-m-d H:i:s') . ' UTC')
                . self::userFact('Güncellendi', $selected->updatedAt()->format('Y-m-d H:i:s') . ' UTC')
                . self::userFact('Aggregate version', (string) $selected->version())
                . '</div>'
                . ($self ? '<div class="ac-user-lockout-note">Kendi access/status kaydın burada değiştirilemez; accidental lockout koruması aktif.</div>' : '')
                . '<div class="ac-actions"><a class="ac-btn" href="' . self::e($basePath->prepend('/moderation/discipline'))
                . '">Ban / warning / restriction</a><a class="ac-btn" href="'
                . self::e($basePath->prepend('/admin/access?analyze_user=' . rawurlencode($selected->id()->value())))
                . '">Yetkiyi analiz et</a></div></section>'
                . '<section class="ac-panel ac-user-access-summary"><div class="ac-heading"><div><h2>Doğrudan access özeti</h2>'
                . '<p class="ac-muted">Permission sonucu değil; bu hesaba doğrudan bağlı primary/secondary group ve role atamalarının özeti.</p></div></div>'
                . '<div class="ac-user-access-grid">'
                . self::userAccessFact('Primary group', $primaryLabel)
                . self::userAccessFact('Secondary groups', $secondaryLabels === [] ? 'Yok' : implode(', ', $secondaryLabels))
                . self::userAccessFact('Direct roles', $roleLabels === [] ? 'Yok' : implode(', ', $roleLabels))
                . '</div></section>'
                . '<div class="ac-user-edit-grid"><section class="ac-panel"><h2>Grup ve rol ataması</h2><form class="ac-form" method="post" action="' . $action . '">'
                . self::hidden($csrf, 'replace_access', $selected->id()->value())
                . '<label>Primary group<select name="primary_group_id">' . $primaryOptions . '</select></label>'
                . '<label>Secondary groups<select multiple name="secondary_group_ids[]">' . $secondaryOptions . '</select></label>'
                . '<label>Direct roles<select multiple name="role_ids[]">' . $roleOptions . '</select></label>'
                . '<button class="ac-btn" type="submit"' . ($self ? ' disabled' : '') . '>Atamaları kaydet</button></form></section>'
                . '<section class="ac-panel"><h2>Hesap durumu</h2><form class="ac-form" method="post" action="' . $action . '">'
                . self::hidden($csrf, 'change_status', $selected->id()->value())
                . '<label>Durum<select name="status">' . $statusOptions . '</select></label>'
                . '<label>Neden<input name="reason" maxlength="120" required placeholder="Örn. account review completed"></label>'
                . '<button class="ac-btn" type="submit"' . ($self ? ' disabled' : '') . '>Durumu güncelle</button></form>'
                . '<p class="ac-muted">Suspended/Banned durumları yalnız moderation discipline üzerinden değiştirilir.</p></section></div>'
                . '<section class="ac-panel ac-user-history"><div class="ac-heading"><div><h2>Kullanıcı geçmişi</h2>'
                . '<p class="ac-muted">User aggregate tarafından tutulan son değişiklik kayıtları.</p></div><span class="ac-count">'
                . count($history) . '</span></div><div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Olay</th><th>Alanlar</th><th>Zaman</th><th>Neden</th></tr></thead><tbody>'
                . ($historyRows !== '' ? $historyRows : '<tr><td colspan="4">Geçmiş yok.</td></tr>')
                . '</tbody></table></div></section></div>';
        }

        $searchSummary = $query === ''
            ? count($rows) . ' son kullanıcı'
            : '“' . self::e($query) . '” için ' . count($rows) . ' sonuç';

        return '<div class="ac-users-shell"><section class="ac-panel ac-users-directory"><div class="ac-heading"><div><h1>Kullanıcı Yönetimi</h1>'
            . '<p class="ac-muted">Gerçek kullanıcı dizininde ara; hesap ayrıntısını aç ve güvenli access/status araçlarını kullan.</p></div>'
            . '<span class="ac-count">' . count($rows) . '</span></div>'
            . '<form class="ac-filter ac-users-toolbar" method="get" action="' . $action . '">'
            . '<label>Kullanıcı/e-posta ara<input name="q" maxlength="80" value="' . self::e($query) . '" placeholder="Kullanıcı adı veya e-posta"></label>'
            . '<button class="ac-btn" type="submit">Ara</button>'
            . ($query !== '' ? '<a class="ac-btn" href="' . $action . '">Temizle</a>' : '')
            . '</form><div class="ac-user-result-summary">' . $searchSummary . '</div>'
            . '<div class="ac-table-wrap"><table class="ac-table ac-user-table"><thead><tr><th>Kullanıcı</th><th>E-posta</th><th>Durum</th><th>Yerel ayar</th><th>Hesap zamanı</th></tr></thead><tbody>'
            . ($table !== '' ? $table : '<tr><td colspan="5">Kullanıcı bulunamadı.</td></tr>')
            . '</tbody></table></div></section>'
            . '<section class="ac-users-selected">' . $detail . '</section></div>';
    }

    private static function userFact(string $label, string $value): string
    {
        return '<div class="ac-user-fact"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong></div>';
    }

    private static function userAccessFact(string $label, string $value): string
    {
        return '<div class="ac-user-access-fact"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong></div>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function access(array $snapshot, BasePath $basePath, string $csrf): string
    {
        $action = self::e($basePath->prepend('/admin/access'));
        $query = trim((string) ($snapshot['ux_query'] ?? ''));
        $selectedAnalyzeUser = (string) ($snapshot['analyze_user_id'] ?? '');
        $selectedPermission = (string) ($snapshot['permission_key'] ?? '');
        $selectedNode = (string) ($snapshot['node_id'] ?? '');

        $groups = '';
        $filteredGroups = 0;
        foreach ($snapshot['groups'] as $group) {
            if (!self::matches($query, [
                (string) $group['name'],
                (string) $group['group_key'],
                (bool) $group['is_system'] ? 'system' : 'custom',
            ])) {
                continue;
            }
            $filteredGroups++;
            $groupUrl = $basePath->prepend('/admin/access?group=' . rawurlencode((string) $group['group_id'])
                . ($query !== '' ? '&q=' . rawurlencode($query) : ''));
            $groups .= '<tr id="group-' . self::e((string) $group['group_id']) . '"><td><a href="' . self::e($groupUrl) . '"><strong>'
                . self::e((string) $group['name']) . '</strong></a></td><td><code>' . self::e((string) $group['group_key'])
                . '</code></td><td><span class="ac-badge">' . ((bool) $group['is_system'] ? 'system' : 'custom') . '</span></td><td>'
                . (int) $group['primary_members'] . '</td><td>' . (int) $group['secondary_members'] . '</td></tr>';
        }

        $selectedGroup = $snapshot['selected_group'] ?? null;
        $groupEditor = '';
        if (is_array($selectedGroup)) {
            $groupEditor = '<section class="ac-panel ac-access-editor"><div class="ac-heading"><div><span class="ac-muted">Seçili grup</span><h2>'
                . self::e((string) $selectedGroup['name']) . '</h2><p><code>' . self::e((string) $selectedGroup['group_key'])
                . '</code></p></div><span class="ac-badge">' . ((bool) $selectedGroup['is_system'] ? 'system' : 'custom') . '</span></div>'
                . '<div class="ac-access-facts">'
                . self::userAccessFact('Primary üyeler', (string) (int) $selectedGroup['primary_members'])
                . self::userAccessFact('Secondary üyeler', (string) (int) $selectedGroup['secondary_members'])
                . self::userAccessFact('Sort order', (string) (int) $selectedGroup['sort_order'])
                . '</div><form class="ac-form" method="post" action="' . $action . '">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_group">'
                . '<input type="hidden" name="group_id" value="' . self::e((string) $selectedGroup['group_id']) . '">'
                . '<div class="ac-row"><label>Key<input name="group_key" maxlength="64" value="' . self::e((string) $selectedGroup['group_key']) . '"></label>'
                . '<label>Ad<input name="name" maxlength="100" value="' . self::e((string) $selectedGroup['name']) . '"></label>'
                . '<label>Sort order<input name="sort_order" type="number" min="0" max="65535" value="' . (int) $selectedGroup['sort_order'] . '"></label></div>'
                . ((bool) $selectedGroup['is_system'] ? '<p class="ac-muted">System grubunun key değeri backend tarafından immutable tutulur.</p>' : '')
                . '<button class="ac-btn" type="submit">Grubu kaydet</button></form></section>';
        }

        $roles = '';
        $filteredRoles = 0;
        foreach ($snapshot['roles'] as $role) {
            if (!self::matches($query, [(string) $role['name'], (string) $role['role_key'], (string) $role['kind']])) {
                continue;
            }
            $filteredRoles++;
            $url = $basePath->prepend('/admin/access?role=' . rawurlencode((string) $role['role_id'])
                . ($query !== '' ? '&q=' . rawurlencode($query) : ''));
            $roles .= '<tr><td><a href="' . self::e($url) . '"><strong>' . self::e((string) $role['name'])
                . '</strong></a></td><td><code>' . self::e((string) $role['role_key']) . '</code></td><td><span class="ac-badge">'
                . self::e((string) $role['kind']) . '</span></td><td>' . (int) $role['priority']
                . '</td><td>' . (int) $role['direct_members'] . '</td></tr>';
        }

        $roleEditor = '<div class="ac-user-empty"><strong>Rol seçilmedi.</strong><span>Bir rol seçerek yapılandırma ve banner görünümünü düzenle.</span></div>';
        $selectedRole = $snapshot['selected_role'];
        if (is_array($selectedRole)) {
            $appearance = $snapshot['selected_appearance'];
            if (!$appearance instanceof RoleAppearance) {
                $appearance = new RoleAppearance(EntityId::fromString((string) $selectedRole['role_id']));
            }
            $roleEditor = '<div class="ac-access-role-grid"><section class="ac-panel ac-access-editor"><div class="ac-heading"><div><span class="ac-muted">Seçili rol</span><h2>'
                . self::e((string) $selectedRole['name']) . '</h2><p><code>' . self::e((string) $selectedRole['role_key'])
                . '</code></p></div><span class="ac-badge">' . self::e((string) $selectedRole['kind']) . '</span></div>'
                . '<div class="ac-access-facts">'
                . self::userAccessFact('Priority', (string) (int) $selectedRole['priority'])
                . self::userAccessFact('Direct üyeler', (string) (int) $selectedRole['direct_members'])
                . self::userAccessFact('Protected', (bool) $selectedRole['is_protected'] ? 'Evet' : 'Hayır')
                . '</div><form class="ac-form" method="post" action="' . $action . '">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_role">'
                . '<input type="hidden" name="role_id" value="' . self::e((string) $selectedRole['role_id']) . '">'
                . '<label>Key<input name="role_key" value="' . self::e((string) $selectedRole['role_key']) . '" maxlength="64"></label>'
                . '<label>Ad<input name="name" value="' . self::e((string) $selectedRole['name']) . '" maxlength="100"></label>'
                . '<div class="ac-row"><label>Kind<select name="kind">' . self::options(['custom','staff','system'], (string) $selectedRole['kind']) . '</select></label>'
                . '<label>Priority<input type="number" name="priority" min="0" max="65535" value="' . (int) $selectedRole['priority'] . '"></label></div>'
                . '<button class="ac-btn" type="submit">Rolü kaydet</button></form></section>'
                . '<section class="ac-panel ac-access-editor"><div class="ac-heading"><div><h2>Banner ve görünüm</h2>'
                . '<p class="ac-muted">Kaydedilmiş role appearance değerleri.</p></div></div><form class="ac-form" method="post" action="' . $action . '">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_appearance">'
                . '<input type="hidden" name="role_id" value="' . self::e((string) $selectedRole['role_id']) . '">'
                . '<div class="ac-row"><label>Text color<input name="text_color" placeholder="#4F46E5" value="' . self::e($appearance->textColor()?->value() ?? '') . '"></label>'
                . '<label>Banner color<input name="banner_color" placeholder="#4F46E5" value="' . self::e($appearance->bannerColor()?->value() ?? '') . '"></label></div>'
                . '<div class="ac-row"><label>Gradient from<input name="gradient_from" value="' . self::e($appearance->gradientFrom()?->value() ?? '') . '"></label>'
                . '<label>Gradient to<input name="gradient_to" value="' . self::e($appearance->gradientTo()?->value() ?? '') . '"></label>'
                . '<label>Angle<input type="number" name="gradient_angle" min="0" max="360" value="' . $appearance->gradientAngle() . '"></label></div>'
                . '<label>Banner text<input name="banner_text" maxlength="64" value="' . self::e($appearance->bannerText() ?? '') . '"></label>'
                . '<div class="ac-row"><label>Icon<select name="icon"><option value="">Yok</option>' . self::options(['shield','star','crown','hammer','check','diamond'], $appearance->icon()?->value ?? '') . '</select></label>'
                . '<label>Pattern<select name="pattern">' . self::options(['none','stripes','dots','grid','diagonal'], $appearance->pattern()->value) . '</select></label>'
                . '<label>Animation<select name="animation">' . self::options(['none','pulse','glow','shimmer','rainbow'], $appearance->animation()->value) . '</select></label></div>'
                . '<div class="ac-checks">' . self::check('show_mobile','Mobil', $appearance->showMobile())
                . self::check('show_profile','Profil', $appearance->showProfile())
                . self::check('show_posts','Mesajlar', $appearance->showPosts()) . '</div>'
                . '<button class="ac-btn" type="submit">Görünümü kaydet</button></form>'
                . '<div class="ac-role-preview"><strong>Kaydedilmiş görünüm önizlemesi</strong><span style="'
                . self::e(self::appearancePreviewStyle($appearance)) . '">' . self::e((string) $selectedRole['name'])
                . ($appearance->bannerText() !== null ? ' · ' . self::e($appearance->bannerText()) : '')
                . '</span><small class="ac-muted">Bu önizleme yalnız kayıtlı değeri gösterir; permission ve rol önceliğini değiştirmez.</small></div></section></div>';
        }

        $analysis = $snapshot['analysis'];
        $analysisHtml = '<div class="ac-user-empty"><strong>Analiz bekleniyor.</strong><span>Kullanıcı + permission seçerek ALLOW/DENY sonucunun hangi katmandan geldiğini incele.</span></div>';
        if ($analysis instanceof PermissionAnalysis) {
            $layers = '';
            foreach ($analysis->layers() as $layer) {
                $steps = '';
                foreach ($layer->steps() as $step) {
                    $steps .= '<li>' . self::e($step->explanation()) . '</li>';
                }
                $layers .= '<div class="ac-analysis-layer"><strong>' . self::e($layer->label()) . ' · '
                    . self::e($layer->state()->value) . '</strong><p>' . self::e($layer->explanation()) . '</p>'
                    . ($steps === '' ? '' : '<ul>' . $steps . '</ul>') . '</div>';
            }
            $analysisHtml = '<div class="ac-permission-result" data-result="' . ($analysis->isAllowed() ? 'allow' : 'deny') . '"><strong>'
                . ($analysis->isAllowed() ? 'ALLOW' : 'DENY') . ' · ' . self::e($analysis->permissionKey()->value())
                . '</strong><p>' . self::e($analysis->summary()) . '</p></div><div class="ac-stack">' . $layers . '</div>';
        }

        $userOptions = '';
        foreach ($snapshot['users'] ?? [] as $user) {
            $id = (string) $user['user_id'];
            $userOptions .= '<option value="' . self::e($id) . '"' . ($selectedAnalyzeUser === $id ? ' selected' : '') . '>'
                . self::e((string) $user['username']) . '</option>';
        }
        $permissionOptions = '';
        foreach ($snapshot['permissions'] as $permission) {
            $key = (string) $permission['permission_key'];
            $permissionOptions .= '<option value="' . self::e($key) . '"' . ($selectedPermission === $key ? ' selected' : '') . '>'
                . self::e($key) . '</option>';
        }
        $nodeOptions = '<option value="">Global</option>';
        foreach ($snapshot['nodes'] as $node) {
            $id = (string) $node['node_id'];
            $nodeOptions .= '<option value="' . self::e($id) . '"' . ($selectedNode === $id ? ' selected' : '') . '>'
                . self::e((string) $node['title']) . ' · ' . self::e((string) $node['node_type']) . '</option>';
        }

        $filter = '<form class="ac-filter ac-access-filter" method="get" action="' . $action . '"><label>Grup veya rol ara<input name="q" maxlength="80" value="'
            . self::e($query) . '" placeholder="Ad, key veya rol türü"></label><button class="ac-btn" type="submit">Filtrele</button>'
            . ($query !== '' ? '<a class="ac-btn" href="' . $action . '">Filtreyi sıfırla</a>' : '') . '</form>';

        $overview = '<section class="ac-access-overview" aria-label="Access özeti">'
            . self::accessStat('Gruplar', count($snapshot['groups']), 'Kayıtlı group tanımları')
            . self::accessStat('Roller', count($snapshot['roles']), 'Staff/custom/system roller')
            . self::accessStat('Permission', count($snapshot['permissions']), 'Analyzer tarafından okunabilir izinler')
            . self::accessStat('Node', count($snapshot['nodes']), 'Node-scope analiz hedefleri')
            . '</section>';

        $create = '<div class="ac-access-create-grid"><details class="ac-panel ac-access-create"><summary>Yeni grup oluştur</summary>'
            . '<form class="ac-form" method="post" action="' . $action . '"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_group">'
            . '<label>Key<input name="group_key" maxlength="64" required></label><label>Ad<input name="name" maxlength="100" required></label>'
            . '<label>Sort order<input name="sort_order" type="number" min="0" max="65535" value="100"></label><button class="ac-btn" type="submit">Grup oluştur</button></form></details>'
            . '<details class="ac-panel ac-access-create"><summary>Yeni rol oluştur</summary><form class="ac-form" method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_role">'
            . '<label>Key<input name="role_key" maxlength="64" required></label><label>Ad<input name="name" maxlength="100" required></label>'
            . '<div class="ac-row"><label>Kind<select name="kind"><option value="custom">custom</option><option value="staff">staff</option></select></label>'
            . '<label>Priority<input name="priority" type="number" min="0" max="65535" value="100"></label></div>'
            . '<button class="ac-btn" type="submit">Rol oluştur</button></form></details></div>';

        $directories = '<div class="ac-access-directory-grid"><section class="ac-panel"><div class="ac-heading"><div><h2>Gruplar</h2>'
            . '<p class="ac-muted">Primary/secondary üyelik sayılarını gerçek assignment tablolarından gösterir.</p></div><span class="ac-count">'
            . $filteredGroups . '</span></div><div class="ac-table-wrap"><table class="ac-table ac-access-table"><thead><tr><th>Ad</th><th>Key</th><th>Tür</th><th>Primary</th><th>Secondary</th></tr></thead><tbody>'
            . ($groups !== '' ? $groups : '<tr><td colspan="5">Filtreyle eşleşen grup yok.</td></tr>') . '</tbody></table></div></section>'
            . '<section class="ac-panel"><div class="ac-heading"><div><h2>Roller</h2><p class="ac-muted">Priority ve direct member sayıları gerçek role assignment verisidir.</p></div>'
            . '<span class="ac-count">' . $filteredRoles . '</span></div><div class="ac-table-wrap"><table class="ac-table ac-access-table"><thead><tr><th>Ad</th><th>Key</th><th>Kind</th><th>Priority</th><th>Üye</th></tr></thead><tbody>'
            . ($roles !== '' ? $roles : '<tr><td colspan="5">Filtreyle eşleşen rol yok.</td></tr>') . '</tbody></table></div></section></div>';

        $analyzer = '<section class="ac-panel ac-permission-analyzer"><div class="ac-heading"><div><h2>Permission analyzer</h2>'
            . '<p class="ac-muted">Production permission engine katmanlarını kullanır; ayrı bir ACP yetki algoritması yoktur.</p></div></div>'
            . '<form class="ac-form" method="get" action="' . $action . '"><div class="ac-row">'
            . '<label>Kullanıcı<select name="analyze_user" required><option value="">Seç</option>' . $userOptions . '</select></label>'
            . '<label>Permission<select name="permission" required><option value="">Seç</option>' . $permissionOptions . '</select></label>'
            . '<label>Forum/node<select name="node">' . $nodeOptions . '</select></label></div><button class="ac-btn" type="submit">Analiz et</button></form>'
            . $analysisHtml . '</section>';

        return '<div class="ac-access-shell"><section class="ac-panel"><div class="ac-heading"><div><h1>Grup, Rol ve Yetki Yönetimi</h1>'
            . '<p class="ac-muted">Gruplar, roller, role appearance ve gerçek permission analyzer tek yoğun yönetim çalışma alanında.</p></div></div>'
            . $filter . '</section>' . $overview . $create . $directories . $groupEditor
            . '<section class="ac-access-role-section">' . $roleEditor . '</section>' . $analyzer . '</div>';
    }

    private static function accessStat(string $label, int $value, string $description): string
    {
        return '<article class="ac-access-stat"><span>' . self::e($label) . '</span><strong>' . $value
            . '</strong><small>' . self::e($description) . '</small></article>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function forums(array $snapshot, BasePath $basePath, string $csrf): string
    {
        $action = self::e($basePath->prepend('/admin/forums'));
        $query = trim((string) ($snapshot['ux_query'] ?? ''));
        $nodeTitles = [];
        foreach ($snapshot['nodes'] as $candidate) {
            if ($candidate instanceof ForumNode) {
                $nodeTitles[$candidate->id()->value()] = $candidate->title();
            }
        }

        $rows = '';
        $filtered = 0;
        foreach ($snapshot['nodes'] as $node) {
            if (!$node instanceof ForumNode) {
                continue;
            }
            if (!self::matches($query, [$node->title(), $node->slug()->value(), $node->type()->value, $node->visibility()->value])) {
                continue;
            }
            $filtered++;
            $stat = $snapshot['stats'][$node->id()->value()] ?? ['threads'=>0,'posts'=>0];
            $url = $basePath->prepend('/admin/forums?node=' . rawurlencode($node->id()->value())
                . ($query !== '' ? '&q=' . rawurlencode($query) : ''));
            $parent = $node->parentId()?->value();
            $rows .= '<tr><td><a href="' . self::e($url) . '"><strong>' . self::e($node->title())
                . '</strong></a><small class="ac-node-slug">/' . self::e($node->slug()->value()) . '</small></td>'
                . '<td><span class="ac-badge">' . self::e($node->type()->value) . '</span></td>'
                . '<td><span class="ac-badge">' . self::e($node->visibility()->value) . '</span></td>'
                . '<td>' . self::e($parent === null ? 'Root' : ($nodeTitles[$parent] ?? $parent)) . '</td>'
                . '<td>' . (int) $stat['threads'] . '</td><td>' . (int) $stat['posts'] . '</td></tr>';
        }

        $selected = $snapshot['selected'];
        $nodeId = $selected instanceof ForumNode ? $selected->id()->value() : '';
        $type = $selected instanceof ForumNode ? $selected->type()->value : ForumNodeType::Forum->value;
        $settings = $selected instanceof ForumNode ? $selected->forumSettings() : null;
        $selectedStat = $selected instanceof ForumNode
            ? ($snapshot['stats'][$selected->id()->value()] ?? ['threads'=>0,'posts'=>0])
            : ['threads'=>0,'posts'=>0];

        $parentOptions = '<option value="">Root</option>';
        foreach ($snapshot['nodes'] as $node) {
            if (!$node instanceof ForumNode || ($selected instanceof ForumNode && $node->id()->equals($selected->id()))) {
                continue;
            }
            $parentOptions .= '<option value="' . self::e($node->id()->value()) . '"'
                . ($selected instanceof ForumNode && $selected->parentId()?->equals($node->id()) ? ' selected' : '')
                . '>' . self::e($node->title()) . '</option>';
        }

        $editor = '<form class="ac-form ac-node-editor" method="post" action="' . $action . '">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_node">'
            . ($nodeId !== '' ? '<input type="hidden" name="node_id" value="' . self::e($nodeId) . '">' : '')
            . '<div class="ac-row"><label>Tür<select name="node_type">' . self::options(['category','forum','page','link'], $type) . '</select></label>'
            . '<label>Parent<select name="parent_id">' . $parentOptions . '</select></label>'
            . '<label>Visibility<select name="visibility">' . self::options(['listed','unlisted','disabled'], $selected instanceof ForumNode ? $selected->visibility()->value : 'listed') . '</select></label></div>'
            . '<div class="ac-row"><label>Başlık<input name="title" maxlength="150" value="' . self::e($selected instanceof ForumNode ? $selected->title() : '') . '" required></label>'
            . '<label>Slug<input name="slug" maxlength="100" value="' . self::e($selected instanceof ForumNode ? $selected->slug()->value() : '') . '" required></label>'
            . '<label>Sort<input type="number" name="sort_order" min="0" max="4294967295" value="' . ($selected instanceof ForumNode ? $selected->sortOrder() : 0) . '"></label></div>'
            . '<label>Açıklama<textarea name="description" maxlength="500">' . self::e($selected instanceof ForumNode ? $selected->description() : '') . '</textarea></label>'
            . '<details class="ac-node-options" open><summary>Forum davranışı</summary><div class="ac-node-options-body"><div class="ac-checks">'
            . self::check('allow_new_threads','Yeni konu', $settings?->allowNewThreads() ?? true)
            . self::check('allow_replies','Yanıt', $settings?->allowReplies() ?? true)
            . self::check('require_thread_approval','Konu onayı', $settings?->requireThreadApproval() ?? false)
            . self::check('require_post_approval','Mesaj onayı', $settings?->requirePostApproval() ?? false)
            . '</div><div class="ac-row"><label>Default sort<select name="default_thread_sort">' . self::options(['last_post','created','title'], $settings?->defaultThreadSort()->value ?? 'last_post') . '</select></label>'
            . '<label>Threads/page<input type="number" name="threads_per_page" min="5" max="100" value="' . ($settings?->threadsPerPage() ?? 20) . '"></label></div></div></details>'
            . '<details class="ac-node-options"><summary>Page / link payload</summary><div class="ac-node-options-body">'
            . '<label>Page content<textarea name="page_content">' . self::e($selected instanceof ForumNode ? ($selected->pageContent() ?? '') : '') . '</textarea></label>'
            . '<div class="ac-row"><label>Link target<input name="link_target" maxlength="2048" value="' . self::e($selected instanceof ForumNode ? ($selected->linkTarget()?->value() ?? '') : '') . '"></label>'
            . '<div class="ac-checks">' . self::check('link_new_window','Yeni pencere', $selected instanceof ForumNode && $selected->linkNewWindow()) . '</div></div></div></details>'
            . ($selected instanceof ForumNode ? '<p class="ac-muted">Mevcut node tipi backend tarafından değiştirilemez; type conversion denemeleri fail-closed reddedilir.</p>' : '')
            . '<button class="ac-btn" type="submit">' . ($selected instanceof ForumNode ? 'Node’u güncelle' : 'Yeni node oluştur') . '</button></form>';

        $filter = '<form class="ac-filter ac-forum-filter" method="get" action="' . $action . '"><label>Node ara<input name="q" maxlength="80" value="'
            . self::e($query) . '" placeholder="Başlık, slug, tür veya görünürlük"></label><button class="ac-btn" type="submit">Filtrele</button>'
            . ($query !== '' ? '<a class="ac-btn" href="' . $action . '">Filtreyi sıfırla</a>' : '') . '</form>';

        $totals = $snapshot['totals'];
        $overview = '<section class="ac-forum-overview" aria-label="Forum ve node özeti">'
            . self::accessStat('Node', count($snapshot['nodes']), 'Kategori, forum, page ve link')
            . self::accessStat('Konular', (int) $totals['threads'], 'Tüm forum thread kayıtları')
            . self::accessStat('Mesajlar', (int) $totals['posts'], 'Tüm post kayıtları')
            . self::accessStat('Onay bekleyen', (int) $totals['thread_pending'] + (int) $totals['post_pending'], 'Thread + post moderation kuyruğu')
            . '</section>';

        $selection = '<div class="ac-user-empty"><strong>Node seçilmedi.</strong><span>Listeden bir node seçerek gerçek hierarchy ve type payload ayarlarını düzenle.</span></div>';
        if ($selected instanceof ForumNode) {
            $parent = $selected->parentId()?->value();
            $selection = '<section class="ac-panel ac-node-summary"><div class="ac-heading"><div><span class="ac-muted">Seçili node</span><h2>'
                . self::e($selected->title()) . '</h2><p>/' . self::e($selected->slug()->value()) . '</p></div><span class="ac-badge">'
                . self::e($selected->type()->value) . '</span></div><div class="ac-access-facts">'
                . self::userAccessFact('Visibility', $selected->visibility()->value)
                . self::userAccessFact('Parent', $parent === null ? 'Root' : ($nodeTitles[$parent] ?? $parent))
                . self::userAccessFact('Sort order', (string) $selected->sortOrder())
                . self::userAccessFact('Konular', (string) (int) $selectedStat['threads'])
                . self::userAccessFact('Mesajlar', (string) (int) $selectedStat['posts'])
                . self::userAccessFact('Node ID', $selected->id()->value())
                . '</div></section>';
        }

        return '<div class="ac-forum-shell"><section class="ac-panel"><div class="ac-heading"><div><h1>Forum ve Node Yönetimi</h1>'
            . '<p class="ac-muted">Gerçek node hiyerarşisini, görünürlüğü ve içerik sayaçlarını tek yoğun yönetim yüzeyinde düzenle.</p></div></div>'
            . $filter . '</section>' . $overview
            . '<section class="ac-panel ac-node-directory"><div class="ac-heading"><div><h2>Node dizini</h2><p class="ac-muted">Hierarchy ve içerik yoğunluğu birlikte gösterilir.</p></div><span class="ac-count">'
            . $filtered . '</span></div><div class="ac-table-wrap"><table class="ac-table ac-node-table"><thead><tr><th>Node</th><th>Tür</th><th>Visibility</th><th>Parent</th><th>Konu</th><th>Mesaj</th></tr></thead><tbody>'
            . ($rows !== '' ? $rows : '<tr><td colspan="6">Filtreyle eşleşen node yok.</td></tr>') . '</tbody></table></div></section>'
            . $selection . '<section class="ac-panel ac-node-editor-panel"><div class="ac-heading"><div><h2>'
            . ($selected instanceof ForumNode ? 'Node düzenle' : 'Yeni node') . '</h2><p class="ac-muted">Repository hierarchy doğrulaması ve type invariant’ları backend authority olarak kalır.</p></div></div>'
            . $editor . '</section></div>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function content(array $snapshot, BasePath $basePath): string
    {
        $t = $snapshot['totals'];
        $capabilities = $snapshot['capabilities'];
        $actions = '';
        if (($capabilities['content_manager'] ?? false) === true) {
            $actions .= '<a class="ac-content-action" href="' . self::e($basePath->prepend('/content-manager')) . '"><strong>User Content Manager</strong><span>Kullanıcı içerik geçmişi ve yetkili içerik işlemleri.</span></a>';
        }
        if (($capabilities['moderation'] ?? false) === true) {
            $actions .= '<a class="ac-content-action" href="' . self::e($basePath->prepend('/moderation/approval')) . '"><strong>Approval Queue</strong><span>Bekleyen thread/post onaylarını gerçek moderasyon servisinde aç.</span></a>';
        }
        $actions .= '<a class="ac-content-action" href="' . self::e($basePath->prepend('/moderation/freshness')) . '"><strong>Thread Freshness</strong><span>Güncellik akışını sahip moderation route’unda yönet.</span></a>';

        return '<div class="ac-content-shell"><section class="ac-panel"><div class="ac-heading"><div><h1>İçerik ACP</h1>'
            . '<p class="ac-muted">İçerik toplamları salt-okunur; mutation işlemleri ilgili Content Manager ve Moderation servislerinde kalır.</p></div></div></section>'
            . '<section class="ac-content-overview">'
            . self::accessStat('Konular', (int) $t['threads'], 'Toplam thread')
            . self::accessStat('Mesajlar', (int) $t['posts'], 'Toplam post')
            . self::accessStat('Konu onayı', (int) $t['thread_pending'], 'Moderation pending')
            . self::accessStat('Mesaj onayı', (int) $t['post_pending'], 'Moderation pending')
            . self::accessStat('Silinmiş konu', (int) $t['deleted_threads'], 'Soft-deleted thread')
            . self::accessStat('Silinmiş mesaj', (int) $t['deleted_posts'], 'Soft-deleted post')
            . '</section><section class="ac-panel"><div class="ac-heading"><div><h2>Operasyon araçları</h2>'
            . '<p class="ac-muted">Backend permission kontrollerini atlamadan ilgili sahip servise geçiş yapar.</p></div></div>'
            . '<div class="ac-content-actions">' . $actions . '</div></section></div>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function moderation(array $snapshot, BasePath $basePath): string
    {
        $m = $snapshot['moderation'];
        $c = $snapshot['capabilities'];
        $cards = '';
        if ($c['moderation']) {
            $cards .= self::linkCard('Moderation Workspace', 'Raporlar, approval, tasks ve abuse.', '/moderation', $basePath);
        }
        if ($c['reports']) {
            $cards .= self::linkCard('Reports', (int) $m['reports'] . ' aktif report group.', '/moderation', $basePath);
        }
        if ($c['discipline']) {
            $cards .= self::linkCard('Warnings / Bans', (int) $m['active_warnings'] . ' aktif warning · '
                . (int) $m['active_bans'] . ' aktif ban/suspension.', '/moderation/discipline', $basePath);
        }
        if ($c['audit']) {
            $cards .= self::linkCard('Audit', 'Core administration audit stream.', '/moderation/audit', $basePath);
        }

        $auditRows = '';
        foreach ($snapshot['audit'] as $event) {
            if (!$event instanceof AuditEvent) {
                continue;
            }
            $auditRows .= '<tr><td>' . self::e($event->occurredAt->format('Y-m-d H:i:s')) . '</td><td>'
                . self::e($event->action->value()) . '</td><td>' . self::e($event->targetType . ':' . $event->targetId)
                . '</td><td>' . self::e($event->actorUserId->value()) . '</td></tr>';
        }

        return '<section class="ac-panel"><h1>Moderasyon, Report, Ban ve Audit</h1><div class="ac-kpis">'
            . self::kpi('Aktif report', (int) $m['reports'])
            . self::kpi('Moderasyon görevi', (int) $m['tasks'])
            . self::kpi('Aktif warning', (int) $m['active_warnings'])
            . self::kpi('Aktif ban/suspension', (int) $m['active_bans'])
            . '</div><div class="ac-grid" style="margin-top:12px">' . $cards . '</div></section>'
            . ($c['audit'] ? '<section class="ac-panel"><h2>Son audit eventleri</h2><div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Zaman</th><th>Action</th><th>Target</th><th>Actor</th></tr></thead><tbody>'
                . ($auditRows !== '' ? $auditRows : '<tr><td colspan="4">Audit kaydı yok.</td></tr>')
                . '</tbody></table></div></section>' : '');
    }

    private static function linkCard(string $title, string $description, string $path, BasePath $basePath): string
    {
        return '<article class="ac-card"><h3>' . self::e($title) . '</h3><p>' . self::e($description)
            . '</p><a class="ac-btn" href="' . self::e($basePath->prepend($path)) . '">Aç</a></article>';
    }

    private static function kpi(string $label, int $value): string
    {
        return '<div class="ac-kpi"><strong>' . $value . '</strong><span>' . self::e($label) . '</span></div>';
    }

    private static function hidden(string $csrf, string $action, string $userId): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="' . self::e($action) . '">'
            . '<input type="hidden" name="user_id" value="' . self::e($userId) . '">';
    }

    /** @param list<string> $values */
    private static function options(array $values, string $selected): string
    {
        $html = '';
        foreach ($values as $value) {
            $html .= '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
                . self::e($value) . '</option>';
        }
        return $html;
    }

    private static function check(string $name, string $label, bool $checked): string
    {
        return '<label><input type="checkbox" name="' . self::e($name) . '" value="1"'
            . ($checked ? ' checked' : '') . '> ' . self::e($label) . '</label>';
    }

    private static function qualityGuidance(AdminCommunitySection $section): string
    {
        return match ($section) {
            AdminCommunitySection::Users => AdminUxQualityHtml::guidance(
                'Kullanıcıyı ara, hesap geçmişini incele ve doğrudan grup/rol atamalarını kontrollü biçimde yönet.',
                'Kendi hesabının erişimi bu yüzeyden değiştirilemez; ban ve suspension Discipline workflow’una bırakılır.',
                'Kaydetmeden önce seçili kullanıcının geçmişi ve mevcut doğrudan atamaları görünür.',
                'Her mutasyon Administration audit’e yazılır; disiplin yaptırımları kendi revoke/appeal akışını kullanır.',
            ),
            AdminCommunitySection::Access => AdminUxQualityHtml::guidance(
                'Grup/rol listesini filtrele, permission analyzer ile gerçek efektif yetkinin nedenini gör.',
                'System/protected anahtarları backend tarafından korunur; yeni rol varsayılan olarak custom/staff kapsamındadır.',
                'Permission analyzer değişiklik yapmadan Allow/Deny katmanlarını açıklar; rol görünümü için kaydedilmiş değer önizlemesi gösterilir.',
                'Override ve rol değişiklikleri audit kaydıyla izlenir; kalıcı silme bu yüzeyde sunulmaz.',
            ),
            AdminCommunitySection::Forums => AdminUxQualityHtml::guidance(
                'Node listesini başlık, slug, tür veya görünürlükle filtrele; seçili node’u aynı hiyerarşi kurallarıyla düzenle.',
                'Yeni node güvenli forum varsayılanlarıyla başlar; mevcut node türü bu ekrandan dönüştürülemez.',
                'Tablodaki mevcut durum ve sayaçlar kaydetmeden önce doğrulama bağlamı sağlar.',
                'Değişiklikler audit edilir; yıkıcı node silme bu yüzeyde sunulmaz.',
            ),
            AdminCommunitySection::Content => AdminUxQualityHtml::guidance(
                'İçerik toplamlarını incele ve gerçek yönetim işini ilgili Content Manager / queue yüzeyinde aç.',
                'Bu özet ekranı veri değiştirmez ve yeni bir permission yolu oluşturmaz.',
                'KPI değerleri salt-okunur doğrulama görünümüdür.',
                'Mutasyon olmadığı için rollback gerekmez; hedef yüzeylerin kendi audit/undo kuralları geçerlidir.',
            ),
            AdminCommunitySection::Moderation => AdminUxQualityHtml::guidance(
                'Rapor, discipline ve audit durumunu özetle; işlemi tek yetkili moderasyon workspace’inde sürdür.',
                'Bu ACP özeti yaptırım üretmez; mevcut moderation permission ve workflow’ları authoritative kalır.',
                'Sayaçlar ve audit bağlantısı işlem öncesi durumu doğrulamaya yarar.',
                'Warning/ban/restriction geri alma davranışı Discipline ve audit akışında yönetilir.',
            ),
        };
    }

    /** @param list<string> $values */
    private static function matches(string $query, array $values): bool
    {
        if ($query === '') {
            return true;
        }

        $needle = strtolower($query);
        foreach ($values as $value) {
            if (str_contains(strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function appearancePreviewStyle(RoleAppearance $appearance): string
    {
        $styles = [];
        if ($appearance->textColor() !== null) {
            $styles[] = 'color:' . $appearance->textColor()->value();
        }
        if ($appearance->gradientFrom() !== null && $appearance->gradientTo() !== null) {
            $styles[] = 'background:linear-gradient(' . $appearance->gradientAngle() . 'deg,'
                . $appearance->gradientFrom()->value() . ',' . $appearance->gradientTo()->value() . ')';
            $styles[] = 'padding:8px 10px';
            $styles[] = 'border-radius:8px';
        } elseif ($appearance->bannerColor() !== null) {
            $styles[] = 'background:' . $appearance->bannerColor()->value();
            $styles[] = 'padding:8px 10px';
            $styles[] = 'border-radius:8px';
        }

        return implode(';', $styles);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
