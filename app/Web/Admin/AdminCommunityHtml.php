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

        return '<section class="ac-community"><style>'
            . '.ac-community{display:grid;gap:16px}.ac-tabs{display:flex;gap:7px;flex-wrap:wrap}.ac-tab,.ac-btn{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:8px 11px;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);text-decoration:none}.ac-tab[aria-current="page"]{outline:2px solid var(--accent);outline-offset:1px}'
            . '.ac-panel{border:1px solid var(--line);background:var(--panel);border-radius:14px;padding:16px}.ac-panel h1,.ac-panel h2,.ac-panel h3{margin-top:0}.ac-muted{color:var(--muted)}'
            . '.ac-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}.ac-card{border:1px solid var(--line);background:var(--panel2);border-radius:12px;padding:13px}.ac-card h3{margin:0 0 6px}.ac-card p{margin:4px 0}'
            . '.ac-form{display:grid;gap:10px}.ac-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.ac-form label{display:grid;gap:5px;font-size:.92rem}.ac-form input,.ac-form select,.ac-form textarea{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:9px;background:var(--panel2);color:var(--text);padding:9px}.ac-form select[multiple]{min-height:150px}.ac-form textarea{min-height:100px;resize:vertical}.ac-checks{display:flex;gap:12px;flex-wrap:wrap}.ac-checks label{display:flex;align-items:center;gap:6px}.ac-checks input{width:auto}'
            . '.ac-table-wrap{overflow:auto}.ac-table{width:100%;border-collapse:collapse;min-width:700px}.ac-table th,.ac-table td{text-align:left;padding:9px;border-bottom:1px solid var(--line);vertical-align:top}.ac-table th{font-size:.85rem;color:var(--muted)}'
            . '.ac-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.ac-kpi{border:1px solid var(--line);background:var(--panel2);border-radius:12px;padding:14px}.ac-kpi strong{display:block;font-size:1.45rem}.ac-badge{display:inline-flex;border:1px solid var(--line);border-radius:999px;padding:3px 8px;font-size:.82rem}.ac-good{font-weight:700}.ac-danger{font-weight:700}.ac-actions{display:flex;gap:7px;flex-wrap:wrap}.ac-stack{display:grid;gap:10px}.ac-analysis-layer{border:1px solid var(--line);border-radius:10px;padding:10px;background:var(--panel2)}'
            . '@media(max-width:700px){.ac-actions{display:grid}.ac-actions>*{width:100%}.ac-btn{width:100%;box-sizing:border-box}}'
            . '</style>'
            . $breadcrumbs
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
        $groups = $accessSnapshot['groups'];
        $roles = $accessSnapshot['roles'];
        $action = self::e($basePath->prepend('/admin/users'));

        $table = '';
        foreach ($rows as $row) {
            $url = $basePath->prepend('/admin/users?user=' . rawurlencode((string) $row['user_id']));
            $table .= '<tr><td><a href="' . self::e($url) . '">' . self::e((string) $row['username'])
                . '</a></td><td>' . self::e((string) $row['email']) . '</td><td>'
                . self::e((string) $row['status']) . '</td><td>'
                . self::e((string) $row['updated_at_utc']) . '</td></tr>';
        }

        $detail = '<p class="ac-muted">Bir kullanıcı seçildiğinde hesap geçmişi ve doğrudan grup/rol atamaları burada yönetilir. Ban ve suspension bu ekranda yazılmaz; Discipline workflow kullanılır.</p>';
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

            $historyRows = '';
            foreach ($history as $entry) {
                $historyRows .= '<tr><td>' . self::e($entry->eventType) . '</td><td>'
                    . self::e(implode(', ', $entry->changedFields)) . '</td><td>'
                    . self::e($entry->occurredAt->format('Y-m-d H:i:s')) . '</td><td>'
                    . self::e($entry->reasonCode ?? '—') . '</td></tr>';
            }

            $detail = '<div class="ac-grid"><section class="ac-card"><h3>' . self::e($selected->username()->display())
                . '</h3><p>' . self::e($selected->email()->value()) . '</p><p><span class="ac-badge">'
                . self::e($selected->status()->value) . '</span></p>'
                . ($self ? '<p class="ac-muted">Kendi access/status kaydın burada değiştirilemez; accidental lockout koruması aktif.</p>' : '')
                . '<div class="ac-actions"><a class="ac-btn" href="' . self::e($basePath->prepend('/moderation/discipline'))
                . '">Ban / warning / restriction</a><a class="ac-btn" href="'
                . self::e($basePath->prepend('/admin/access?analyze_user=' . rawurlencode($selected->id()->value())))
                . '">Yetkiyi analiz et</a></div></section>'
                . '<section class="ac-card"><h3>Grup ve rol ataması</h3><form class="ac-form" method="post" action="' . $action . '">'
                . self::hidden($csrf, 'replace_access', $selected->id()->value())
                . '<label>Primary group<select name="primary_group_id">' . $primaryOptions . '</select></label>'
                . '<label>Secondary groups<select multiple name="secondary_group_ids[]">' . $secondaryOptions . '</select></label>'
                . '<label>Direct roles<select multiple name="role_ids[]">' . $roleOptions . '</select></label>'
                . '<button class="ac-btn" type="submit"' . ($self ? ' disabled' : '') . '>Atamaları kaydet</button></form></section>'
                . '<section class="ac-card"><h3>Hesap durumu</h3><form class="ac-form" method="post" action="' . $action . '">'
                . self::hidden($csrf, 'change_status', $selected->id()->value())
                . '<label>Durum<select name="status">' . $statusOptions . '</select></label>'
                . '<label>Neden<input name="reason" maxlength="120" required placeholder="Örn. account review completed"></label>'
                . '<button class="ac-btn" type="submit"' . ($self ? ' disabled' : '') . '>Durumu güncelle</button></form>'
                . '<p class="ac-muted">Suspended/Banned durumları yalnız moderation discipline üzerinden değiştirilir.</p></section></div>'
                . '<section class="ac-panel"><h3>Kullanıcı geçmişi</h3><div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Olay</th><th>Alanlar</th><th>Zaman</th><th>Neden</th></tr></thead><tbody>'
                . ($historyRows !== '' ? $historyRows : '<tr><td colspan="4">Geçmiş yok.</td></tr>')
                . '</tbody></table></div></section>';
        }

        return '<section class="ac-panel"><h1>Kullanıcı Yönetimi</h1>'
            . '<form class="ac-form" method="get" action="' . $action . '"><div class="ac-row"><label>Kullanıcı/e-posta ara<input name="q" maxlength="80"></label></div><button class="ac-btn" type="submit">Ara</button></form>'
            . '<div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Kullanıcı</th><th>E-posta</th><th>Durum</th><th>Güncellendi</th></tr></thead><tbody>'
            . ($table !== '' ? $table : '<tr><td colspan="4">Kullanıcı bulunamadı.</td></tr>')
            . '</tbody></table></div></section><section class="ac-panel"><h2>Seçili kullanıcı</h2>' . $detail . '</section>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function access(array $snapshot, BasePath $basePath, string $csrf): string
    {
        $action = self::e($basePath->prepend('/admin/access'));
        $groups = '';
        foreach ($snapshot['groups'] as $group) {
            $groups .= '<tr id="group-' . self::e((string) $group['group_id']) . '"><td>'
                . self::e((string) $group['name']) . '</td><td>' . self::e((string) $group['group_key'])
                . '</td><td>' . ((bool) $group['is_system'] ? 'system' : 'custom') . '</td><td>'
                . ((int) $group['primary_members'] + (int) $group['secondary_members']) . '</td></tr>';
        }

        $roles = '';
        foreach ($snapshot['roles'] as $role) {
            $url = $basePath->prepend('/admin/access?role=' . rawurlencode((string) $role['role_id']));
            $roles .= '<tr><td><a href="' . self::e($url) . '">' . self::e((string) $role['name'])
                . '</a></td><td>' . self::e((string) $role['role_key']) . '</td><td>'
                . self::e((string) $role['kind']) . '</td><td>' . (int) $role['priority']
                . '</td><td>' . (int) $role['direct_members'] . '</td></tr>';
        }

        $roleEditor = '<p class="ac-muted">Bir rol seçerek banner/görünüm ayarını düzenleyebilirsin.</p>';
        $selectedRole = $snapshot['selected_role'];
        if (is_array($selectedRole)) {
            $appearance = $snapshot['selected_appearance'];
            if (!$appearance instanceof RoleAppearance) {
                $appearance = new RoleAppearance(EntityId::fromString((string) $selectedRole['role_id']));
            }
            $roleEditor = '<div class="ac-grid"><section class="ac-card"><h3>Rol</h3><form class="ac-form" method="post" action="' . $action . '">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_role">'
                . '<input type="hidden" name="role_id" value="' . self::e((string) $selectedRole['role_id']) . '">'
                . '<label>Key<input name="role_key" value="' . self::e((string) $selectedRole['role_key']) . '" maxlength="64"></label>'
                . '<label>Ad<input name="name" value="' . self::e((string) $selectedRole['name']) . '" maxlength="100"></label>'
                . '<label>Kind<select name="kind">' . self::options(['custom','staff','system'], (string) $selectedRole['kind']) . '</select></label>'
                . '<label>Priority<input type="number" name="priority" min="0" max="65535" value="' . (int) $selectedRole['priority'] . '"></label>'
                . '<button class="ac-btn" type="submit">Rolü kaydet</button></form></section>'
                . '<section class="ac-card"><h3>Banner ve görünüm</h3><form class="ac-form" method="post" action="' . $action . '">'
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
                . '<button class="ac-btn" type="submit">Görünümü kaydet</button></form></section></div>';
        }

        $analysis = $snapshot['analysis'];
        $analysisHtml = '<p class="ac-muted">Kullanıcı + permission seçerek Allow/Deny sonucunun hangi katmandan geldiğini görebilirsin.</p>';
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
            $analysisHtml = '<p class="' . ($analysis->isAllowed() ? 'ac-good' : 'ac-danger') . '">'
                . ($analysis->isAllowed() ? 'ALLOW' : 'DENY') . ' · ' . self::e($analysis->permissionKey()->value())
                . '</p><p>' . self::e($analysis->summary()) . '</p><div class="ac-stack">' . $layers . '</div>';
        }

        $userOptions = '';
        foreach ($snapshot['users'] ?? [] as $user) {
            $userOptions .= '<option value="' . self::e((string) $user['user_id']) . '">' . self::e((string) $user['username']) . '</option>';
        }
        $permissionOptions = '';
        foreach ($snapshot['permissions'] as $permission) {
            $permissionOptions .= '<option value="' . self::e((string) $permission['permission_key']) . '">'
                . self::e((string) $permission['permission_key']) . '</option>';
        }
        $nodeOptions = '<option value="">Global</option>';
        foreach ($snapshot['nodes'] as $node) {
            $nodeOptions .= '<option value="' . self::e((string) $node['node_id']) . '">'
                . self::e((string) $node['title']) . ' · ' . self::e((string) $node['node_type']) . '</option>';
        }

        return '<section class="ac-panel"><h1>Grup, Rol, Banner ve Permission Analyzer</h1><div class="ac-grid">'
            . '<section class="ac-card"><h3>Yeni grup</h3><form class="ac-form" method="post" action="' . $action . '"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_group">'
            . '<label>Key<input name="group_key" maxlength="64" required></label><label>Ad<input name="name" maxlength="100" required></label><label>Sort order<input name="sort_order" type="number" min="0" max="65535" value="100"></label><button class="ac-btn" type="submit">Grup oluştur</button></form></section>'
            . '<section class="ac-card"><h3>Yeni rol</h3><form class="ac-form" method="post" action="' . $action . '"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_role">'
            . '<label>Key<input name="role_key" maxlength="64" required></label><label>Ad<input name="name" maxlength="100" required></label><label>Kind<select name="kind"><option value="custom">custom</option><option value="staff">staff</option></select></label><label>Priority<input name="priority" type="number" min="0" max="65535" value="100"></label><button class="ac-btn" type="submit">Rol oluştur</button></form></section></div>'
            . '<div class="ac-grid"><section class="ac-card"><h3>Gruplar</h3><div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Ad</th><th>Key</th><th>Tür</th><th>Üye</th></tr></thead><tbody>' . $groups . '</tbody></table></div></section>'
            . '<section class="ac-card"><h3>Roller</h3><div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Ad</th><th>Key</th><th>Kind</th><th>Priority</th><th>Üye</th></tr></thead><tbody>' . $roles . '</tbody></table></div></section></div>'
            . '<section class="ac-panel"><h2>Seçili rol</h2>' . $roleEditor . '</section>'
            . '<section class="ac-panel"><h2>Permission analyzer</h2><form class="ac-form" method="get" action="' . $action . '"><div class="ac-row">'
            . '<label>Kullanıcı<select name="analyze_user" required><option value="">Seç</option>' . $userOptions . '</select></label>'
            . '<label>Permission<select name="permission" required><option value="">Seç</option>' . $permissionOptions . '</select></label>'
            . '<label>Forum/node<select name="node">' . $nodeOptions . '</select></label></div><button class="ac-btn" type="submit">Analiz et</button></form>'
            . $analysisHtml . '</section>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function forums(array $snapshot, BasePath $basePath, string $csrf): string
    {
        $action = self::e($basePath->prepend('/admin/forums'));
        $rows = '';
        foreach ($snapshot['nodes'] as $node) {
            if (!$node instanceof ForumNode) {
                continue;
            }
            $stat = $snapshot['stats'][$node->id()->value()] ?? ['threads'=>0,'posts'=>0];
            $url = $basePath->prepend('/admin/forums?node=' . rawurlencode($node->id()->value()));
            $rows .= '<tr><td><a href="' . self::e($url) . '">' . self::e($node->title()) . '</a></td><td>'
                . self::e($node->type()->value) . '</td><td>' . self::e($node->visibility()->value)
                . '</td><td>' . (int) $stat['threads'] . '</td><td>' . (int) $stat['posts'] . '</td></tr>';
        }

        $selected = $snapshot['selected'];
        $nodeId = $selected instanceof ForumNode ? $selected->id()->value() : '';
        $type = $selected instanceof ForumNode ? $selected->type()->value : ForumNodeType::Forum->value;
        $settings = $selected instanceof ForumNode ? $selected->forumSettings() : null;
        $parentOptions = '<option value="">Root</option>';
        foreach ($snapshot['nodes'] as $node) {
            if (!$node instanceof ForumNode || ($selected instanceof ForumNode && $node->id()->equals($selected->id()))) {
                continue;
            }
            $parentOptions .= '<option value="' . self::e($node->id()->value()) . '"'
                . ($selected instanceof ForumNode && $selected->parentId()?->equals($node->id()) ? ' selected' : '')
                . '>' . self::e($node->title()) . '</option>';
        }

        $editor = '<form class="ac-form" method="post" action="' . $action . '"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '"><input type="hidden" name="action" value="save_node">'
            . ($nodeId !== '' ? '<input type="hidden" name="node_id" value="' . self::e($nodeId) . '">' : '')
            . '<div class="ac-row"><label>Tür<select name="node_type">' . self::options(['category','forum','page','link'], $type) . '</select></label>'
            . '<label>Parent<select name="parent_id">' . $parentOptions . '</select></label>'
            . '<label>Visibility<select name="visibility">' . self::options(['listed','unlisted','disabled'], $selected instanceof ForumNode ? $selected->visibility()->value : 'listed') . '</select></label></div>'
            . '<div class="ac-row"><label>Başlık<input name="title" maxlength="150" value="' . self::e($selected instanceof ForumNode ? $selected->title() : '') . '" required></label>'
            . '<label>Slug<input name="slug" maxlength="100" value="' . self::e($selected instanceof ForumNode ? $selected->slug()->value() : '') . '" required></label>'
            . '<label>Sort<input type="number" name="sort_order" min="0" max="4294967295" value="' . ($selected instanceof ForumNode ? $selected->sortOrder() : 0) . '"></label></div>'
            . '<label>Açıklama<textarea name="description" maxlength="500">' . self::e($selected instanceof ForumNode ? $selected->description() : '') . '</textarea></label>'
            . '<div class="ac-card"><h3>Forum ayarları</h3><div class="ac-checks">'
            . self::check('allow_new_threads','Yeni konu', $settings?->allowNewThreads() ?? true)
            . self::check('allow_replies','Yanıt', $settings?->allowReplies() ?? true)
            . self::check('require_thread_approval','Konu onayı', $settings?->requireThreadApproval() ?? false)
            . self::check('require_post_approval','Mesaj onayı', $settings?->requirePostApproval() ?? false)
            . '</div><div class="ac-row"><label>Default sort<select name="default_thread_sort">' . self::options(['last_post','created','title'], $settings?->defaultThreadSort()->value ?? 'last_post') . '</select></label>'
            . '<label>Threads/page<input type="number" name="threads_per_page" min="5" max="100" value="' . ($settings?->threadsPerPage() ?? 20) . '"></label></div></div>'
            . '<label>Page content<textarea name="page_content">' . self::e($selected instanceof ForumNode ? ($selected->pageContent() ?? '') : '') . '</textarea></label>'
            . '<div class="ac-row"><label>Link target<input name="link_target" maxlength="2048" value="' . self::e($selected instanceof ForumNode ? ($selected->linkTarget()?->value() ?? '') : '') . '"></label>'
            . '<div class="ac-checks">' . self::check('link_new_window','Yeni pencere', $selected instanceof ForumNode && $selected->linkNewWindow()) . '</div></div>'
            . '<button class="ac-btn" type="submit">' . ($selected instanceof ForumNode ? 'Node’u güncelle' : 'Yeni node oluştur') . '</button></form>';

        return '<section class="ac-panel"><h1>Forum ve Node Yönetimi</h1><p class="ac-muted">Kategori, forum, page ve link node’ları aynı hiyerarşi doğrulamasını kullanır. Mevcut node tipi bu ekrandan dönüştürülemez.</p>'
            . '<div class="ac-table-wrap"><table class="ac-table"><thead><tr><th>Node</th><th>Tür</th><th>Visibility</th><th>Konu</th><th>Mesaj</th></tr></thead><tbody>'
            . $rows . '</tbody></table></div></section><section class="ac-panel"><h2>'
            . ($selected instanceof ForumNode ? 'Node düzenle' : 'Yeni node') . '</h2>' . $editor . '</section>';
    }

    /** @param array<string,mixed> $snapshot */
    private static function content(array $snapshot, BasePath $basePath): string
    {
        $t = $snapshot['totals'];
        return '<section class="ac-panel"><h1>İçerik ACP</h1><div class="ac-kpis">'
            . self::kpi('Konular', (int) $t['threads'])
            . self::kpi('Mesajlar', (int) $t['posts'])
            . self::kpi('Onay bekleyen konu', (int) $t['thread_pending'])
            . self::kpi('Onay bekleyen mesaj', (int) $t['post_pending'])
            . self::kpi('Silinmiş konu', (int) $t['deleted_threads'])
            . self::kpi('Silinmiş mesaj', (int) $t['deleted_posts'])
            . '</div><div class="ac-actions" style="margin-top:12px">'
            . '<a class="ac-btn" href="' . self::e($basePath->prepend('/content-manager')) . '">User Content Manager</a>'
            . '<a class="ac-btn" href="' . self::e($basePath->prepend('/moderation/approval')) . '">Approval Queue</a>'
            . '<a class="ac-btn" href="' . self::e($basePath->prepend('/moderation/freshness')) . '">Thread Freshness</a>'
            . '</div><p class="ac-muted">İçerik mutation’ları mevcut Content Manager ve Moderation servislerinden geçer; bu dashboard onların backend permission kontrollerini atlamaz.</p></section>';
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

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
