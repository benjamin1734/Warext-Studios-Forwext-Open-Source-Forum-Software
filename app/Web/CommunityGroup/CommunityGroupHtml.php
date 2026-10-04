<?php

declare(strict_types=1);

namespace Forwext\App\Web\CommunityGroup;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\CommunityGroup\CommunityGroup;
use Forwext\Core\CommunityGroup\CommunityGroupMember;
use Forwext\Core\Routing\BasePath;

final class CommunityGroupHtml
{
    /**
     * @param list<CommunityGroup> $groups
     */
    public static function index(
        array $groups,
        ?string $query,
        int $page,
        bool $hasMore,
        BasePath $basePath,
        bool $authenticated,
        bool $canCreate,
    ): string {
        $rows = '';
        foreach ($groups as $group) {
            $rows .= self::groupCard($group, $basePath);
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty group-empty"><strong>Grup bulunamadı.</strong>'
                . '<span>Arama ölçütünü değiştir veya toplulukta ilk grubu oluştur.</span></div>';
        }

        $actions = '<div class="group-head-actions">';
        if ($authenticated) {
            $actions .= '<a class="fx-btn" href="' . self::e($basePath->prepend('/groups/mine')) . '">Klanlarım</a>';
            if ($canCreate) {
                $actions .= '<a class="fx-btn fx-btn--primary" href="'
                    . self::e($basePath->prepend('/groups/mine#create-group')) . '">Grup oluştur</a>';
            }
        } else {
            $actions .= '<a class="fx-btn fx-btn--primary" href="' . self::e($basePath->prepend('/login'))
                . '">Katılmak için giriş yap</a>';
        }
        $actions .= '</div>';

        $body = '<section class="community-group-page discovery-page">'
            . '<header class="surface-head group-head"><div><span class="forum-eyebrow">TOPLULUK</span>'
            . '<h1>Klanlar & Gruplar</h1><p>Topluluk gruplarını keşfet, üyelik durumlarını gör ve katıl.</p></div>'
            . $actions . '</header>'
            . '<form class="surface-panel group-search" action="' . self::e($basePath->prepend('/groups')) . '" method="get">'
            . '<label><span>Grup ara</span><input type="search" name="q" maxlength="120" value="'
            . self::e($query ?? '') . '" placeholder="İsim veya kısa açıklama"></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Ara</button></form>'
            . '<section class="surface-panel group-directory"><div class="group-list">' . $rows . '</div>'
            . self::pagination('/groups', $query, $page, $hasMore, $basePath) . '</section></section>';

        return ProfileHtml::page('Klanlar & Gruplar', $body, $basePath, authenticated:$authenticated);
    }

    /**
     * @param list<CommunityGroup> $groups
     * @param array<string,CommunityGroupMember> $memberships
     */
    public static function mine(
        array $groups,
        array $memberships,
        BasePath $basePath,
        string $csrf,
        bool $canCreate,
        bool $created,
    ): string {
        $rows = '';
        foreach ($groups as $group) {
            $membership = $memberships[$group->groupId->value()] ?? null;
            $state = $membership === null
                ? 'Üyelik bulunamadı'
                : self::membershipLabel($membership);
            $rows .= '<article class="group-mine-row"><div><a href="'
                . self::e($basePath->prepend('/groups/' . rawurlencode($group->groupId->value()))) . '"><strong>'
                . self::e($group->name) . '</strong></a><span>' . self::e($group->tagline) . '</span></div>'
                . '<div class="group-mine-meta"><span>' . self::e($state) . '</span><strong>'
                . $group->activeMemberCount . ' üye</strong></div></article>';
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty"><strong>Henüz bir grubun yok.</strong>'
                . '<span>Bir gruba katıldığında veya grup oluşturduğunda burada görünür.</span></div>';
        }

        $create = '';
        if ($canCreate) {
            $create = '<section id="create-group" class="surface-panel group-create-panel"><div><h2>Yeni grup oluştur</h2>'
                . '<p>Grup adı, kısa açıklama ve üyelik politikasını belirle.</p></div>'
                . '<form action="' . self::e($basePath->prepend('/groups/mine')) . '" method="post">'
                . self::csrf($csrf) . '<input type="hidden" name="action" value="create">'
                . '<label><span>Grup adı</span><input name="name" maxlength="120" required></label>'
                . '<label><span>Slug</span><input name="slug" maxlength="120" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" '
                . 'placeholder="ornek-klan" required></label>'
                . '<label class="group-create-wide"><span>Kısa açıklama</span><input name="tagline" maxlength="240"></label>'
                . '<label><span>Üyelik</span><select name="join_policy">'
                . '<option value="approval">Onaylı katılım</option><option value="open">Açık katılım</option>'
                . '<option value="closed">Kapalı</option></select></label>'
                . '<label class="group-create-wide"><span>Açıklama</span>'
                . '<textarea name="description" maxlength="20000" rows="7" required></textarea></label>'
                . '<button class="fx-btn fx-btn--primary" type="submit">Grubu oluştur</button></form></section>';
        }

        $body = '<section class="community-group-page group-mine-page discovery-page">'
            . '<header class="surface-head"><div><span class="forum-eyebrow">TOPLULUK · HESABIM</span>'
            . '<h1>Klanlarım</h1><p>Sahip olduğun, yönettiğin, katıldığın veya onay beklediğin gruplar.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/groups')) . '">Tüm gruplar</a></header>'
            . ($created ? '<div class="surface-notice" role="status">Grup oluşturuldu.</div>' : '')
            . $create
            . '<section class="surface-panel group-mine-list"><h2>Üyeliklerim</h2>' . $rows . '</section></section>';

        return ProfileHtml::page('Klanlarım', $body, $basePath, authenticated:true);
    }

    /**
     * @param list<CommunityGroupMember> $members
     * @param array<string,string> $usernames
     */
    public static function detail(
        CommunityGroup $group,
        array $members,
        array $usernames,
        ?CommunityGroupMember $viewerMembership,
        BasePath $basePath,
        bool $authenticated,
        ?string $csrf,
        bool $canManage,
        bool $canManageRoles,
        ?string $status,
    ): string {
        $groupPath = '/groups/' . rawurlencode($group->groupId->value());
        $notice = match ($status) {
            'joined' => '<div class="surface-notice" role="status">Gruba katıldın.</div>',
            'requested' => '<div class="surface-notice" role="status">Üyelik isteğin gönderildi.</div>',
            'left' => '<div class="surface-notice" role="status">Grup üyeliğin sona erdi.</div>',
            'managed' => '<div class="surface-notice" role="status">Üyelik güncellendi.</div>',
            'created' => '<div class="surface-notice" role="status">Grup oluşturuldu.</div>',
            default => '',
        };

        $joinAction = '';
        if (!$authenticated) {
            $joinAction = '<a class="fx-btn fx-btn--primary" href="' . self::e($basePath->prepend('/login'))
                . '">Katılmak için giriş yap</a>';
        } elseif ($viewerMembership?->roleKey === 'owner') {
            $joinAction = '<span class="group-membership-state is-owner">Grup sahibi</span>';
        } elseif ($viewerMembership?->active()) {
            $joinAction = '<form action="' . self::e($basePath->prepend($groupPath)) . '" method="post">'
                . self::csrf((string) $csrf) . '<input type="hidden" name="action" value="leave">'
                . '<button class="fx-btn" type="submit">Gruptan ayrıl</button></form>';
        } elseif ($viewerMembership?->state === 'pending') {
            $joinAction = '<div class="group-membership-actions"><span class="group-membership-state">Onay bekliyor</span>'
                . '<form action="' . self::e($basePath->prepend($groupPath)) . '" method="post">'
                . self::csrf((string) $csrf) . '<input type="hidden" name="action" value="leave">'
                . '<button class="fx-btn" type="submit">İsteği iptal et</button></form></div>';
        } elseif ($group->joinPolicy === 'closed') {
            $joinAction = '<span class="group-membership-state">Yeni üyeliğe kapalı</span>';
        } else {
            $label = $group->joinPolicy === 'open' ? 'Gruba katıl' : 'Katılım isteği gönder';
            $joinAction = '<form action="' . self::e($basePath->prepend($groupPath)) . '" method="post">'
                . self::csrf((string) $csrf) . '<input type="hidden" name="action" value="join">'
                . '<button class="fx-btn fx-btn--primary" type="submit">' . self::e($label) . '</button></form>';
        }

        $memberRows = '';
        foreach ($members as $member) {
            $name = $usernames[$member->userId->value()] ?? 'Hesap kullanılamıyor';
            $role = self::roleLabel($member->roleKey);
            $state = $member->state === 'pending' ? 'Onay bekliyor' : $role;
            $actions = '';
            if ($canManage && $member->roleKey !== 'owner' && $csrf !== null) {
                $action = self::e($basePath->prepend($groupPath));
                $buttons = '';
                if ($member->state === 'pending') {
                    $buttons .= '<button class="fx-btn fx-btn--primary" name="member_action" value="approve" type="submit">Onayla</button>';
                } else {
                    if ($canManageRoles && $member->roleKey === 'member') {
                        $buttons .= '<button class="fx-btn" name="member_action" value="promote" type="submit">Moderatör yap</button>';
                    } elseif ($canManageRoles && $member->roleKey === 'moderator') {
                        $buttons .= '<button class="fx-btn" name="member_action" value="demote" type="submit">Üye yap</button>';
                    }
                }
                if ($member->roleKey !== 'moderator' || $canManageRoles) {
                    $buttons .= '<button class="fx-btn fx-btn--danger" name="member_action" value="remove" type="submit">Çıkar</button>';
                }
                if ($buttons !== '') {
                    $actions = '<form class="group-member-actions" action="' . $action . '" method="post">'
                        . self::csrf($csrf) . '<input type="hidden" name="action" value="manage_member">'
                        . '<input type="hidden" name="user_id" value="' . self::e($member->userId->value()) . '">'
                        . $buttons . '</form>';
                }
            }
            $memberRows .= '<article class="group-member-row is-' . self::e($member->state) . '">'
                . '<div class="group-member-avatar" aria-hidden="true">' . self::e(self::initial($name)) . '</div>'
                . '<div><strong>' . self::e($name) . '</strong><span>' . self::e($state) . '</span></div>'
                . $actions . '</article>';
        }
        if ($memberRows === '') {
            $memberRows = '<div class="surface-empty"><strong>Üye bulunmuyor.</strong>'
                . '<span>Aktif üyeler burada listelenir.</span></div>';
        }

        $manageBadge = $canManage
            ? '<span class="group-management-badge">Yönetim erişimi</span>'
            : '';
        $body = '<section class="community-group-page group-detail-page discovery-page">'
            . '<header class="surface-head group-detail-head"><div><a class="surface-back-link" href="'
            . self::e($basePath->prepend('/groups')) . '">← Klanlar & Gruplar</a>'
            . '<span class="forum-eyebrow">TOPLULUK GRUBU</span><h1>' . self::e($group->name) . '</h1>'
            . '<p>' . self::e($group->tagline) . '</p></div><div class="group-detail-actions">'
            . $manageBadge . $joinAction . '</div></header>' . $notice
            . '<div class="group-detail-grid"><section class="surface-panel group-about"><h2>Grup hakkında</h2>'
            . '<div class="group-description">' . nl2br(self::e($group->description), false) . '</div><dl>'
            . self::fact('Üyelik politikası', self::joinPolicyLabel($group->joinPolicy))
            . self::fact('Aktif üye', (string) $group->activeMemberCount)
            . ($canManage ? self::fact('Onay bekleyen', (string) $group->pendingMemberCount) : '')
            . '</dl></section>'
            . '<section class="surface-panel group-members"><div class="group-members-head"><div><h2>Üyeler</h2>'
            . '<p>' . ($canManage ? 'Aktif ve onay bekleyen üyelikleri yönet.' : 'Grubun aktif üyeleri.') . '</p></div>'
            . '</div><div class="group-member-list">' . $memberRows . '</div></section></div></section>';

        return ProfileHtml::page($group->name, $body, $basePath, authenticated:$authenticated);
    }

    private static function groupCard(CommunityGroup $group, BasePath $basePath): string
    {
        $href = self::e($basePath->prepend('/groups/' . rawurlencode($group->groupId->value())));
        return '<article class="group-card"><a class="group-card-mark" href="' . $href . '" aria-hidden="true">'
            . self::e(self::initial($group->name)) . '</a><div class="group-card-copy"><div class="group-card-title">'
            . '<h2><a href="' . $href . '">' . self::e($group->name) . '</a></h2><span>'
            . self::e(self::joinPolicyLabel($group->joinPolicy)) . '</span></div>'
            . '<p>' . self::e($group->tagline) . '</p><div class="group-card-meta"><strong>'
            . $group->activeMemberCount . ' üye</strong><span>Güncelleme '
            . self::e($group->updatedAt->format('d.m.Y')) . '</span></div></div></article>';
    }

    private static function membershipLabel(CommunityGroupMember $membership): string
    {
        if ($membership->state === 'pending') {
            return 'Onay bekliyor';
        }
        return self::roleLabel($membership->roleKey);
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            'owner' => 'Sahip',
            'moderator' => 'Moderatör',
            default => 'Üye',
        };
    }

    private static function joinPolicyLabel(string $policy): string
    {
        return match ($policy) {
            'open' => 'Açık katılım',
            'closed' => 'Kapalı',
            default => 'Onaylı katılım',
        };
    }

    private static function pagination(
        string $path,
        ?string $query,
        int $page,
        bool $hasMore,
        BasePath $basePath,
    ): string {
        if ($page === 1 && !$hasMore) {
            return '';
        }
        $html = '<nav class="surface-pagination" aria-label="Grup sayfaları">';
        if ($page > 1) {
            $html .= '<a href="' . self::e(self::pageUrl($path, $query, $page - 1, $basePath)) . '">← Önceki</a>';
        }
        $html .= '<span aria-current="page">Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a href="' . self::e(self::pageUrl($path, $query, $page + 1, $basePath)) . '">Sonraki →</a>';
        }
        return $html . '</nav>';
    }

    private static function pageUrl(string $path, ?string $query, int $page, BasePath $basePath): string
    {
        $params = ['page'=>$page];
        if ($query !== null && $query !== '') {
            $params['q'] = $query;
        }
        return $basePath->prepend($path . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private static function fact(string $label, string $value): string
    {
        return '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function initial(string $value): string
    {
        $value = trim($value);
        return $value === '' ? '?' : mb_strtoupper(mb_substr($value, 0, 1));
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
