<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Minecraft\Server\MinecraftServer;
use Forwext\Core\Minecraft\Server\MinecraftServerClaim;
use Forwext\Core\Minecraft\Server\MinecraftServerSeason;
use Forwext\Core\Minecraft\Server\MinecraftServerStatistics;
use Forwext\Core\Minecraft\Server\MinecraftServerTeamMember;
use Forwext\Core\Minecraft\Server\MinecraftServerUpdate;
use Forwext\Core\Minecraft\Server\MinecraftServerVoteIntegration;
use Forwext\Core\Minecraft\Server\MinecraftServerVoteSummary;
use Forwext\Core\Routing\BasePath;

final class MinecraftServerHtml
{
    /** @param list<MinecraftServer> $servers */
    public static function directory(
        array $servers,
        BasePath $basePath,
        bool $authenticated,
        ?string $query,
        ?string $edition,
        int $page,
        bool $hasMore,
    ): string {
        $rows = '';
        foreach ($servers as $server) {
            $rows .= self::directoryRow($server, $basePath);
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty minecraft-server-empty"><strong>Sunucu bulunamadı.</strong>'
                . '<span>Filtreyi değiştir veya daha sonra tekrar kontrol et.</span></div>';
        }

        $tabs = '<nav class="surface-tabs minecraft-server-tabs" aria-label="Minecraft sürüm filtreleri">'
            . self::tab('Tümü', null, $edition, $query, $basePath)
            . self::tab('Java', 'java', $edition, $query, $basePath)
            . self::tab('Bedrock', 'bedrock', $edition, $query, $basePath)
            . self::tab('Crossplay', 'crossplay', $edition, $query, $basePath)
            . '</nav>';

        $form = '<form class="surface-panel minecraft-server-filter" action="'
            . self::e($basePath->prepend('/servers')) . '" method="get">'
            . '<label><span>Sunucu ara</span><input type="search" name="q" maxlength="120" value="'
            . self::e($query ?? '') . '" placeholder="Sunucu adı, adres veya oyun modu"></label>';
        if ($edition !== null) {
            $form .= '<input type="hidden" name="edition" value="' . self::e($edition) . '">';
        }
        $form .= '<button class="fx-btn fx-btn--primary" type="submit">Ara</button></form>';

        $pagination = self::pagination($page, $hasMore, $query, $edition, $basePath);
        $body = '<section class="minecraft-server-page discovery-page">'
            . '<header class="surface-head minecraft-server-head"><div><span class="forum-eyebrow">MINECRAFT</span>'
            . '<h1>Minecraft Sunucuları</h1>'
            . '<p>Topluluk sunucularını sürüm, erişilebilirlik ve temel sunucu bilgileriyle keşfet.</p></div></header>'
            . $tabs . $form
            . '<section class="surface-panel minecraft-server-directory"><div class="minecraft-server-list">'
            . $rows . '</div>' . $pagination . '</section></section>';

        return ProfileHtml::page('Minecraft Sunucuları', $body, $basePath, authenticated:$authenticated);
    }

    public static function detail(
        MinecraftServer $server,
        BasePath $basePath,
        bool $authenticated,
        bool $canManage = false,
        bool $canClaim = false,
        ?MinecraftServerVoteSummary $voteSummary = null,
        bool $canVote = false,
        ?string $csrf = null,
        ?string $voteStatus = null,
    ): string
    {
        $status = self::statusLabel($server);
        $verified = $server->verified()
            ? '<span class="minecraft-server-verified">Doğrulandı</span>'
            : '<span class="minecraft-server-unverified">Doğrulanmadı</span>';
        $players = self::players($server);
        $website = self::safeExternal($server->websiteUrl);
        $discord = self::safeExternal($server->discordUrl);

        $links = '';
        if ($website !== null) {
            $links .= '<a class="fx-btn" href="' . self::e($website) . '" rel="nofollow noopener noreferrer">Web sitesi</a>';
        }
        if ($discord !== null) {
            $links .= '<a class="fx-btn" href="' . self::e($discord) . '" rel="nofollow noopener noreferrer">Discord</a>';
        }
        $serverBase = '/servers/' . rawurlencode($server->serverId->value());
        $links .= '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase . '/updates')) . '">Güncellemeler</a>';
        $links .= '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase . '/stats')) . '">İstatistikler</a>';
        $links .= '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase . '/team')) . '">Ekip</a>';
        if ($canManage) {
            $links .= '<a class="fx-btn fx-btn--primary" href="'
                . self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value()) . '/edit'))
                . '">Sunucuyu yönet</a>';
        } elseif ($canClaim) {
            $links .= '<a class="fx-btn fx-btn--primary" href="'
                . self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value()) . '/verify'))
                . '">Sahipliği talep et</a>';
        }

        $votePanel = '';
        if ($voteSummary !== null) {
            $notice = match ($voteStatus) {
                'recorded' => '<div class="surface-notice" role="status">Oyun kaydedildi.</div>',
                'already' => '<div class="surface-notice" role="status">Bugün bu sunucuya zaten oy verdin.</div>',
                default => '',
            };
            $voteAction = '';
            if ($canVote && $csrf !== null) {
                $voteAction = '<form action="'
                    . self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value()) . '/vote'))
                    . '" method="post"><input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                    . '<button class="fx-btn fx-btn--primary" type="submit">Bugün oy ver</button></form>';
            } elseif ($voteSummary->votedToday) {
                $voteAction = '<span class="minecraft-vote-state is-done">Bugünkü oyun kaydedildi</span>';
            } elseif (!$authenticated) {
                $voteAction = '<a class="fx-btn" href="' . self::e($basePath->prepend('/login')) . '">Oy vermek için giriş yap</a>';
            } else {
                $voteAction = '<span class="minecraft-vote-state">Oy verme iznin bulunmuyor</span>';
            }

            $votePanel = $notice . '<section class="surface-panel minecraft-server-vote-panel"><div>'
                . '<span class="forum-eyebrow">TOPLULUK OYU</span><h2>' . $voteSummary->totalVotes . ' oy</h2>'
                . '<p>Son 30 gün: <strong>' . $voteSummary->votesLast30Days . '</strong>. '
                . 'Her hesap aynı sunucuya UTC gününde bir kez oy verebilir.</p></div>'
                . '<div class="minecraft-server-vote-action">' . $voteAction . '</div></section>';
        }

        $body = '<section class="minecraft-server-page minecraft-server-detail discovery-page">'
            . '<header class="surface-head minecraft-server-detail-head"><div>'
            . '<a class="surface-back-link" href="' . self::e($basePath->prepend('/servers')) . '">← Sunucular</a>'
            . '<span class="forum-eyebrow">' . self::e(strtoupper($server->edition)) . ' · '
            . self::e($server->versionLabel !== '' ? $server->versionLabel : 'Sürüm belirtilmedi') . '</span>'
            . '<h1>' . self::e($server->name) . '</h1><p>' . self::e($server->summary) . '</p></div>'
            . '<div class="minecraft-server-detail-actions">' . $verified . $links . '</div></header>'
            . $votePanel
            . '<div class="minecraft-server-detail-grid"><section class="surface-panel minecraft-server-overview">'
            . '<h2>Sunucu bilgileri</h2><dl>'
            . self::fact('Adres', $server->address())
            . self::fact('Durum', $status)
            . self::fact('Oyuncular', $players)
            . self::fact('Oyun modu', $server->gameMode !== '' ? $server->gameMode : 'Belirtilmedi')
            . self::fact('Sürüm', $server->versionLabel !== '' ? $server->versionLabel : 'Belirtilmedi')
            . self::fact('Gecikme', $server->latencyMs === null ? '—' : $server->latencyMs . ' ms')
            . '</dl></section>'
            . '<section class="surface-panel minecraft-server-description"><h2>Hakkında</h2><div>'
            . nl2br(self::e($server->description), false) . '</div>';
        if ($server->motd !== null && trim($server->motd) !== '') {
            $body .= '<div class="minecraft-server-motd"><strong>MOTD</strong><span>' . self::e($server->motd) . '</span></div>';
        }
        $body .= '</section></div></section>';

        return ProfileHtml::page($server->name, $body, $basePath, authenticated:$authenticated);
    }

    /**
     * @param list<MinecraftServer> $candidates
     * @param list<MinecraftServer> $comparison
     * @param list<string> $selectedIds
     */
    public static function comparison(
        array $candidates,
        array $comparison,
        array $selectedIds,
        ?string $warning,
        BasePath $basePath,
        bool $authenticated,
    ): string {
        $selected = array_fill_keys($selectedIds, true);
        $options = '';
        foreach ($candidates as $server) {
            $id = $server->serverId->value();
            $options .= '<label class="minecraft-compare-option"><input type="checkbox" name="server[]" value="'
                . self::e($id) . '"' . (isset($selected[$id]) ? ' checked' : '') . '>'
                . '<span class="minecraft-server-icon" aria-hidden="true">' . self::e(self::initial($server->name)) . '</span>'
                . '<span><strong>' . self::e($server->name) . '</strong><small>'
                . self::e(strtoupper($server->edition) . ' · ' . ($server->versionLabel !== '' ? $server->versionLabel : 'Sürüm yok'))
                . '</small></span></label>';
        }
        if ($options === '') {
            $options = '<div class="surface-empty"><strong>Karşılaştırılabilir sunucu bulunmuyor.</strong>'
                . '<span>Yayınlanmış sunucular burada seçilebilir.</span></div>';
        }

        $notice = $warning === null
            ? ''
            : '<div class="surface-notice" role="status">' . self::e($warning) . '</div>';

        $result = '';
        if ($comparison !== []) {
            $cards = '';
            foreach ($comparison as $server) {
                $href = self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value())));
                $cards .= '<article class="surface-panel minecraft-compare-card">'
                    . '<header><span class="minecraft-server-icon" aria-hidden="true">' . self::e(self::initial($server->name))
                    . '</span><div><h2><a href="' . $href . '">' . self::e($server->name) . '</a></h2><small>'
                    . self::e($server->address()) . '</small></div></header><dl>'
                    . self::fact('Doğrulama', $server->verified() ? 'Doğrulandı' : 'Doğrulanmadı')
                    . self::fact('Durum', self::statusLabel($server))
                    . self::fact('Oyuncular', self::players($server))
                    . self::fact('Edition', strtoupper($server->edition))
                    . self::fact('Sürüm', $server->versionLabel !== '' ? $server->versionLabel : 'Belirtilmedi')
                    . self::fact('Oyun modu', $server->gameMode !== '' ? $server->gameMode : 'Belirtilmedi')
                    . self::fact('Gecikme', $server->latencyMs === null ? '—' : $server->latencyMs . ' ms')
                    . '</dl></article>';
            }
            $result = '<section class="minecraft-compare-results"><h2>Karşılaştırma</h2>'
                . '<div class="minecraft-compare-grid">' . $cards . '</div></section>';
        }

        $body = '<section class="minecraft-server-page minecraft-compare-page discovery-page">'
            . '<header class="surface-head"><div><span class="forum-eyebrow">MINECRAFT</span>'
            . '<h1>Sunucu Karşılaştırma</h1><p>İki ile dört yayınlanmış sunucuyu aynı ölçütlerle karşılaştır.</p></div></header>'
            . $notice
            . '<form class="surface-panel minecraft-compare-form" action="'
            . self::e($basePath->prepend('/servers/compare')) . '" method="get">'
            . '<div class="minecraft-compare-form-head"><div><h2>Sunucuları seç</h2>'
            . '<p>En fazla dört sunucu seçebilirsin.</p></div>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Karşılaştır</button></div>'
            . '<div class="minecraft-compare-options">' . $options . '</div></form>'
            . $result . '</section>';

        return ProfileHtml::page('Sunucu Karşılaştırma', $body, $basePath, authenticated:$authenticated);
    }

    /** @param list<MinecraftServerSeason> $seasons */
    public static function seasons(
        array $seasons,
        ?string $state,
        int $page,
        bool $hasMore,
        BasePath $basePath,
        bool $authenticated,
    ): string {
        $rows = '';
        foreach ($seasons as $season) {
            $rows .= self::seasonRow($season);
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty minecraft-season-empty"><strong>Sezon bulunmuyor.</strong>'
                . '<span>Bu filtrede yayınlanmış sezon kaydı yok.</span></div>';
        }

        $tabs = '<nav class="surface-tabs minecraft-season-tabs" aria-label="Sezon filtreleri">'
            . self::seasonTab('Tümü', null, $state, $basePath)
            . self::seasonTab('Aktif', 'active', $state, $basePath)
            . self::seasonTab('Yaklaşan', 'upcoming', $state, $basePath)
            . self::seasonTab('Tamamlanan', 'closed', $state, $basePath)
            . '</nav>';

        $pagination = self::seasonPagination($page, $hasMore, $state, $basePath);
        $body = '<section class="minecraft-server-page minecraft-season-page discovery-page">'
            . '<header class="surface-head"><div><span class="forum-eyebrow">MINECRAFT</span>'
            . '<h1>Sunucu Sezonları</h1><p>Topluluk sunucularının dönemsel sezonlarını ve katılım yoğunluğunu takip et.</p></div></header>'
            . $tabs . '<section class="surface-panel minecraft-season-list">' . $rows . $pagination . '</section></section>';

        return ProfileHtml::page('Sunucu Sezonları', $body, $basePath, authenticated:$authenticated);
    }

    /**
     * @param list<MinecraftServerUpdate> $updates
     */
    public static function updates(
        MinecraftServer $server,
        array $updates,
        int $page,
        bool $hasMore,
        BasePath $basePath,
        bool $authenticated,
    ): string {
        $rows = '';
        foreach ($updates as $update) {
            $rows .= '<article class="minecraft-update-row"><header><h2>' . self::e($update->title) . '</h2>'
                . '<time datetime="' . self::e($update->createdAt->format(DATE_ATOM)) . '">'
                . self::e($update->createdAt->format('d.m.Y H:i')) . '</time></header>'
                . '<div>' . nl2br(self::e($update->body), false) . '</div></article>';
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty minecraft-update-empty"><strong>Henüz güncelleme yok.</strong>'
                . '<span>Sunucu ekibi yayınladığında güncellemeler burada görünür.</span></div>';
        }

        $pagination = self::updatePagination($server, $page, $hasMore, $basePath);
        $body = '<section class="minecraft-server-page minecraft-update-page discovery-page">'
            . '<header class="surface-head"><div><a class="surface-back-link" href="'
            . self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value()))) . '">← '
            . self::e($server->name) . '</a><span class="forum-eyebrow">MINECRAFT · GÜNCELLEMELER</span>'
            . '<h1>Sunucu Güncellemeleri</h1><p>Sunucu sahibi veya yetkili ekip tarafından yayınlanan güncelleme notları.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend(
                '/servers/' . rawurlencode($server->serverId->value()) . '/stats',
            )) . '">İstatistikler</a></header>'
            . '<section class="surface-panel minecraft-update-list">' . $rows . $pagination . '</section></section>';

        return ProfileHtml::page('Güncellemeler · ' . $server->name, $body, $basePath, authenticated:$authenticated);
    }

    public static function statistics(
        MinecraftServer $server,
        MinecraftServerStatistics $statistics,
        BasePath $basePath,
        bool $authenticated,
    ): string {
        $maxVotes = max(1, ...array_values($statistics->dailyVotes));
        $trend = '';
        foreach ($statistics->dailyVotes as $day=>$votes) {
            $trend .= '<li><time datetime="' . self::e($day) . '">' . self::e(self::shortDay($day)) . '</time>'
                . '<meter min="0" max="' . $maxVotes . '" value="' . $votes . '">'
                . $votes . '</meter><strong>' . $votes . '</strong></li>';
        }

        $body = '<section class="minecraft-server-page minecraft-statistics-page discovery-page">'
            . '<header class="surface-head"><div><a class="surface-back-link" href="'
            . self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value()))) . '">← '
            . self::e($server->name) . '</a><span class="forum-eyebrow">MINECRAFT · İSTATİSTİK</span>'
            . '<h1>Sunucu İstatistikleri</h1><p>Oy geçmişi ve mevcut durum kaydından hesaplanan gerçek sunucu ölçümleri.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend(
                '/servers/' . rawurlencode($server->serverId->value()) . '/updates',
            )) . '">Güncellemeler</a></header>'
            . '<section class="minecraft-stat-grid">'
            . self::statCard('Toplam oy', (string) $statistics->totalVotes)
            . self::statCard('Son 30 gün', (string) $statistics->votesLast30Days)
            . self::statCard('Yayınlanmış güncelleme', (string) $statistics->publishedUpdates)
            . self::statCard('Mevcut oyuncular', self::players($server))
            . self::statCard('Gecikme', $server->latencyMs === null ? '—' : $server->latencyMs . ' ms')
            . self::statCard('Durum', self::statusLabel($server))
            . '</section>'
            . '<section class="surface-panel minecraft-vote-trend"><header><div><h2>30 günlük oy trendi</h2>'
            . '<p>UTC günleri temel alınır. Boş günler sıfır olarak gösterilir.</p></div>'
            . '<span>Son oy: ' . self::e($statistics->lastVoteAt?->format('d.m.Y H:i') ?? '—') . '</span></header>'
            . '<ol>' . $trend . '</ol></section></section>';

        return ProfileHtml::page('İstatistikler · ' . $server->name, $body, $basePath, authenticated:$authenticated);
    }

    /**
     * @param list<MinecraftServer> $servers
     * @param list<MinecraftServerClaim> $claims
     * @param array<string,string> $claimantNames
     */
    public static function manageIndex(
        array $servers,
        array $claims,
        array $claimantNames,
        BasePath $basePath,
    ): string {
        $rows = '';
        foreach ($servers as $server) {
            $href = self::e($basePath->prepend(
                '/servers/' . rawurlencode($server->serverId->value()) . '/manage',
            ));
            $rows .= '<article class="minecraft-manage-row"><div><strong>' . self::e($server->name)
                . '</strong><span>' . self::e($server->address()) . '</span></div><div class="minecraft-manage-row-meta">'
                . '<span>' . self::e(self::listingStateLabel($server->listingState)) . '</span>'
                . '<span>' . ($server->ownerUserId === null ? 'Sahipsiz' : 'Sahipli') . '</span>'
                . '<a class="fx-btn" href="' . $href . '">Yönet</a></div></article>';
        }
        if ($rows === '') {
            $rows = '<div class="surface-empty"><strong>Yönetilebilir sunucu yok.</strong>'
                . '<span>Hesabına bağlı veya yetkin dahilindeki sunucular burada görünür.</span></div>';
        }

        $claimRows = self::claimManagementRows($claims, $claimantNames, $basePath, false, '');
        $body = '<section class="minecraft-server-page minecraft-manage-page discovery-page">'
            . '<header class="surface-head"><div><span class="forum-eyebrow">MINECRAFT</span>'
            . '<h1>Sunucu Yönetimi</h1><p>Sahip olduğun veya yetkin bulunan sunucu kayıtlarını tek yerden yönet.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend('/servers')) . '">Dizine dön</a></header>'
            . '<section class="surface-panel minecraft-manage-list"><h2>Sunucular</h2>' . $rows . '</section>';
        if ($claimRows !== '') {
            $body .= '<section class="surface-panel minecraft-claim-review-list"><h2>Bekleyen sahiplik talepleri</h2>'
                . $claimRows . '</section>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Sunucu Yönetimi', $body, $basePath, authenticated:true);
    }

    /**
     * @param list<MinecraftServerClaim> $claims
     * @param array<string,string> $claimantNames
     * @param list<MinecraftServerUpdate> $updates
     */
    public static function manage(
        MinecraftServer $server,
        array $claims,
        array $claimantNames,
        array $updates,
        string $csrf,
        BasePath $basePath,
        bool $canReviewClaims,
        bool $updated,
        bool $canManageTeam,
        bool $canManageVoteIntegration,
        bool $canManageOwnership,
    ): string {
        $action = self::e($basePath->prepend(
            '/servers/' . rawurlencode($server->serverId->value()) . '/manage',
        ));
        $editAction = self::e($basePath->prepend(
            '/servers/' . rawurlencode($server->serverId->value()) . '/edit',
        ));
        $transferAction = self::e($basePath->prepend(
            '/servers/' . rawurlencode($server->serverId->value()) . '/transfer',
        ));
        $notice = $updated
            ? '<div class="surface-notice" role="status">Sunucu yönetim değişikliği kaydedildi.</div>'
            : '';
        $stateOptions = self::option('draft', 'Taslak', $server->listingState)
            . self::option('published', 'Yayında', $server->listingState);
        if ($canReviewClaims || $server->listingState === 'suspended') {
            $stateOptions .= self::option('suspended', 'Askıya alındı', $server->listingState);
        }
        $serverBase = '/servers/' . rawurlencode($server->serverId->value());
        $headActions = '<div class="minecraft-manage-head-actions">'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase)) . '">Public görünüm</a>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase . '/team')) . '">'
            . ($canManageTeam ? 'Ekibi yönet' : 'Ekip') . '</a>';
        if ($canManageVoteIntegration) {
            $headActions .= '<a class="fx-btn" href="'
                . self::e($basePath->prepend($serverBase . '/vote-settings')) . '">Oy entegrasyonu</a>';
        }
        $headActions .= '</div>';

        $body = '<section class="minecraft-server-page minecraft-manage-page discovery-page">'
            . '<header class="surface-head"><div><a class="surface-back-link" href="'
            . self::e($basePath->prepend('/servers/manage')) . '">← Sunucu yönetimi</a>'
            . '<span class="forum-eyebrow">MINECRAFT · YÖNETİM</span><h1>' . self::e($server->name) . '</h1>'
            . '<p>Listeleme bilgileri, yayın durumu ve sahiplik işlemleri sunucu tarafı yetkileriyle korunur.</p></div>'
            . $headActions . '</header>' . $notice
            . '<form class="surface-panel minecraft-manage-form" action="' . $editAction . '" method="post">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="save"><h2>Sunucu bilgileri</h2>'
            . '<div class="minecraft-manage-fields">'
            . self::field('Sunucu adı', 'name', $server->name, 120)
            . self::field('Kısa açıklama', 'summary', $server->summary, 240)
            . self::field('Sunucu adresi', 'host', $server->host, 255)
            . self::field('Port', 'port', (string) $server->port, 5, 'number')
            . self::field('Sürüm etiketi', 'version_label', $server->versionLabel, 64)
            . self::field('Oyun modu', 'game_mode', $server->gameMode, 64)
            . self::field('Web sitesi', 'website_url', $server->websiteUrl ?? '', 1000, 'url')
            . self::field('Discord', 'discord_url', $server->discordUrl ?? '', 1000, 'url')
            . '<label><span>Edition</span><select name="edition">'
            . self::option('java', 'Java', $server->edition)
            . self::option('bedrock', 'Bedrock', $server->edition)
            . self::option('crossplay', 'Crossplay', $server->edition)
            . '</select></label>'
            . '<label><span>Liste durumu</span><select name="listing_state">' . $stateOptions . '</select></label>'
            . '<label class="minecraft-manage-wide"><span>Açıklama</span><textarea name="description" maxlength="20000" rows="10">'
            . self::e($server->description) . '</textarea></label></div>'
            . '<div class="minecraft-manage-actions"><button class="fx-btn fx-btn--primary" type="submit">Değişiklikleri kaydet</button></div>'
            . '</form>';

        $updateRows = self::updateManagementRows($updates, $action, $csrf);
        $body .= '<section class="surface-panel minecraft-update-management"><div class="minecraft-update-management-head">'
            . '<div><h2>Sunucu güncellemeleri</h2><p>Yayınlanan notlar public güncelleme akışında görünür.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend(
                '/servers/' . rawurlencode($server->serverId->value()) . '/updates',
            )) . '">Public akış</a></div>'
            . '<form class="minecraft-update-compose" action="' . $action . '" method="post">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="publish_update">'
            . '<label><span>Başlık</span><input name="update_title" maxlength="160" required></label>'
            . '<label><span>Güncelleme notu</span><textarea name="update_body" maxlength="10000" rows="6" required></textarea></label>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Güncellemeyi yayınla</button></form>'
            . '<div class="minecraft-update-management-list">' . $updateRows . '</div></section>';

        if ($server->ownerUserId !== null && $canManageOwnership) {
            $body .= '<section class="surface-panel minecraft-ownership-panel"><div><h2>Sahiplik</h2>'
                . '<p>Transfer yalnızca aktif bir hesaba yapılır. Bırakma işlemi kaydı sahipsiz duruma döndürür.</p></div>'
                . '<div class="minecraft-ownership-actions"><form action="' . $transferAction . '" method="post">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="transfer">'
                . '<label><span>Yeni sahip kullanıcı adı</span><input name="target_username" maxlength="64" required></label>'
                . '<button class="fx-btn" type="submit">Sahipliği aktar</button></form>'
                . '<form action="' . $action . '" method="post">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="release">'
                . '<button class="fx-btn fx-btn--danger" type="submit">Sahipliği bırak</button></form></div></section>';
        }

        if ($canReviewClaims) {
            $claimRows = self::claimManagementRows($claims, $claimantNames, $basePath, true, $csrf);
            $body .= '<section class="surface-panel minecraft-claim-review-list"><h2>Sahiplik talepleri</h2>'
                . ($claimRows !== '' ? $claimRows : '<div class="surface-empty"><strong>Talep yok.</strong>'
                . '<span>Bu sunucu için incelenecek sahiplik talebi bulunmuyor.</span></div>') . '</section>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Sunucu Yönetimi · ' . $server->name, $body, $basePath, authenticated:true);
    }

    /**
     * @param list<MinecraftServerTeamMember> $team
     * @param array<string,string> $usernames
     */
    public static function team(
        MinecraftServer $server,
        array $team,
        array $usernames,
        ?string $ownerName,
        bool $canManage,
        ?string $csrf,
        BasePath $basePath,
        bool $authenticated,
        bool $updated,
        bool $canManageVoteIntegration,
    ): string {
        $serverBase = '/servers/' . rawurlencode($server->serverId->value());
        $action = self::e($basePath->prepend($serverBase . '/team'));
        $rows = '<article class="minecraft-team-member is-owner"><div class="minecraft-team-avatar" aria-hidden="true">'
            . self::e(self::initial($ownerName ?? $server->name)) . '</div><div><div class="minecraft-team-title"><strong>'
            . self::e($ownerName ?? 'Sahip atanmamış') . '</strong><span>Sunucu sahibi</span></div>'
            . '<p>Sahiplik; ekip rolünden ayrıdır ve yalnız sahiplik yaşam döngüsü üzerinden değişir.</p></div></article>';

        foreach ($team as $member) {
            $name = $usernames[$member->userId->value()] ?? 'Hesap kullanılamıyor';
            $roleLabel = $member->roleKey === 'manager' ? 'Yönetici' : 'Ekip üyesi';
            $title = $member->publicTitle ?? $roleLabel;
            $profile = $name === 'Hesap kullanılamıyor'
                ? self::e($name)
                : '<a href="' . self::e($basePath->prepend('/members/' . rawurlencode($name))) . '">'
                    . self::e($name) . '</a>';
            $remove = '';
            if ($canManage && $csrf !== null) {
                $remove = '<form action="' . $action . '" method="post">'
                    . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                    . '<input type="hidden" name="action" value="remove_member">'
                    . '<input type="hidden" name="user_id" value="' . self::e($member->userId->value()) . '">'
                    . '<button class="fx-btn fx-btn--danger" type="submit">Ekipten çıkar</button></form>';
            }
            $rows .= '<article class="minecraft-team-member is-' . self::e($member->roleKey) . '">'
                . '<div class="minecraft-team-avatar" aria-hidden="true">' . self::e(self::initial($name)) . '</div>'
                . '<div><div class="minecraft-team-title"><strong>' . $profile . '</strong><span>'
                . self::e($roleLabel) . '</span></div><p>' . self::e($title) . '</p></div>' . $remove . '</article>';
        }

        $managePanel = '';
        if ($canManage && $csrf !== null) {
            $managePanel = '<section class="surface-panel minecraft-team-manage"><div><h2>Ekip üyesi ekle veya güncelle</h2>'
                . '<p>Aynı kullanıcıyı tekrar kaydetmek rol ve görünen unvanı günceller.</p></div>'
                . '<form action="' . $action . '" method="post">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="save_member">'
                . '<label><span>Kullanıcı adı</span><input name="username" maxlength="64" required></label>'
                . '<label><span>Rol</span><select name="role_key">'
                . '<option value="manager">Yönetici</option><option value="member">Ekip üyesi</option></select></label>'
                . '<label><span>Public unvan</span><input name="public_title" maxlength="64" placeholder="Örn. Teknik sorumlu"></label>'
                . '<button class="fx-btn fx-btn--primary" type="submit">Ekip kaydını kaydet</button></form>'
                . '<p class="surface-help">Yönetici rolü mevcut manage_own izniyle sunucu bilgileri ve güncellemeleri yönetebilir; '
                . 'sahiplik, ekip ve entegrasyon anahtarlarını yönetemez.</p></section>';
        }

        $headActions = '<div class="minecraft-team-head-actions"><a class="fx-btn" href="'
            . self::e($basePath->prepend($serverBase)) . '">Sunucuya dön</a>';
        if ($canManageVoteIntegration) {
            $headActions .= '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase . '/vote-settings'))
                . '">Oy entegrasyonu</a>';
        }
        $headActions .= '</div>';

        $body = '<section class="minecraft-server-page minecraft-team-page discovery-page">'
            . '<header class="surface-head"><div><span class="forum-eyebrow">MINECRAFT · EKİP</span><h1>'
            . self::e($server->name) . ' Ekibi</h1><p>Sunucu sahibi ve atanmış ekip üyeleri.</p></div>'
            . $headActions . '</header>'
            . ($updated ? '<div class="surface-notice" role="status">Ekip kaydı güncellendi.</div>' : '')
            . $managePanel
            . '<section class="surface-panel minecraft-team-list"><h2>Ekip</h2>' . $rows . '</section></section>';

        return ProfileHtml::page('Ekip · ' . $server->name, $body, $basePath, authenticated:$authenticated);
    }

    public static function voteSettings(
        MinecraftServer $server,
        ?MinecraftServerVoteIntegration $integration,
        string $csrf,
        BasePath $basePath,
        ?string $oneTimeToken,
        bool $updated,
    ): string {
        $serverBase = '/servers/' . rawurlencode($server->serverId->value());
        $action = self::e($basePath->prepend($serverBase . '/vote-settings'));
        $feedPath = $basePath->prepend($serverBase . '/vote-feed');
        $hasToken = $integration?->hasToken() ?? false;
        $enabled = $integration?->enabled ?? false;
        $status = $enabled ? 'Etkin' : 'Kapalı';
        $tokenPrefix = $integration?->tokenPrefix === null ? 'Henüz oluşturulmadı' : $integration->tokenPrefix . '…';
        $rotatedAt = $integration?->lastRotatedAt?->format('d.m.Y H:i') ?? '—';

        $secretPanel = '';
        if ($oneTimeToken !== null) {
            $secretPanel = '<section class="surface-panel minecraft-vote-secret" role="status"><div>'
                . '<span class="forum-eyebrow">YALNIZCA BİR KEZ GÖSTERİLİR</span><h2>Yeni integration token</h2>'
                . '<p>Bu token veritabanında plaintext olarak tutulmaz. Şimdi güvenli bir yere kaydet.</p></div>'
                . '<code>' . self::e($oneTimeToken) . '</code></section>';
        }

        $toggle = '';
        if ($hasToken) {
            $toggle = '<form class="minecraft-vote-settings-toggle" action="' . $action . '" method="post">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="toggle">'
                . '<label><input type="checkbox" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '>'
                . '<span>Vote feed endpoint’ini etkinleştir</span></label>'
                . '<button class="fx-btn fx-btn--primary" type="submit">Durumu kaydet</button></form>';
        }

        $body = '<section class="minecraft-server-page minecraft-vote-settings-page discovery-page">'
            . '<header class="surface-head"><div><a class="surface-back-link" href="'
            . self::e($basePath->prepend($serverBase . '/manage')) . '">← Sunucu yönetimi</a>'
            . '<span class="forum-eyebrow">MINECRAFT · OY ENTEGRASYONU</span><h1>' . self::e($server->name)
            . '</h1><p>Web oylarını sunucu tarafı entegrasyonuna güvenli bearer token ile aktar.</p></div>'
            . '<a class="fx-btn" href="' . self::e($basePath->prepend($serverBase . '/team')) . '">Ekip</a></header>'
            . ($updated ? '<div class="surface-notice" role="status">Oy entegrasyonu durumu güncellendi.</div>' : '')
            . $secretPanel
            . '<section class="surface-panel minecraft-vote-settings-summary"><h2>Entegrasyon durumu</h2><dl>'
            . self::fact('Durum', $status)
            . self::fact('Token', $tokenPrefix)
            . self::fact('Son token yenileme', $rotatedAt)
            . '</dl></section>'
            . '<section class="surface-panel minecraft-vote-settings-endpoint"><div><h2>Vote feed endpoint</h2>'
            . '<p>İsteklerde <code>Authorization: Bearer TOKEN</code> başlığı kullanılır. Son oylar newest-first döner; '
            . 'her kayıt benzersiz <code>vote_id</code> içerir.</p></div>'
            . '<code>GET ' . self::e($feedPath) . '?limit=100</code></section>'
            . '<section class="surface-panel minecraft-vote-settings-actions">' . $toggle
            . '<form action="' . $action . '" method="post">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="action" value="rotate_token">'
            . '<button class="fx-btn' . ($hasToken ? ' fx-btn--danger' : ' fx-btn--primary') . '" type="submit">'
            . ($hasToken ? 'Tokenı yenile' : 'Token oluştur') . '</button></form>'
            . '<p class="surface-help">Token yenilemek eski tokenı anında geçersiz kılar. Token olmadan endpoint etkinleştirilemez.</p>'
            . '</section></section>';

        return ProfileHtml::page('Oy Entegrasyonu · ' . $server->name, $body, $basePath, authenticated:true);
    }

    /** @param list<MinecraftServerClaim> $claims */
    public static function claim(
        MinecraftServer $server,
        array $claims,
        string $csrf,
        BasePath $basePath,
        bool $submitted,
    ): string {
        $action = self::e($basePath->prepend(
            '/servers/' . rawurlencode($server->serverId->value()) . '/verify',
        ));
        $history = '';
        foreach ($claims as $claim) {
            $history .= '<article class="minecraft-claim-history-row"><div><strong>'
                . self::e(self::claimStateLabel($claim->state)) . '</strong><span>'
                . self::e($claim->createdAt->format('d.m.Y H:i')) . '</span></div><p>'
                . self::e($claim->proofNote) . '</p>'
                . ($claim->reviewNote === null ? '' : '<small>İnceleme notu: ' . self::e($claim->reviewNote) . '</small>')
                . '</article>';
        }
        if ($history === '') {
            $history = '<div class="surface-empty"><strong>Daha önce talep göndermedin.</strong>'
                . '<span>Sunucuyla ilişkini açıklayan doğrulanabilir bilgi ekle.</span></div>';
        }

        $body = '<section class="minecraft-server-page minecraft-claim-page discovery-page">'
            . '<header class="surface-head"><div><a class="surface-back-link" href="'
            . self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value()))) . '">← '
            . self::e($server->name) . '</a><span class="forum-eyebrow">MINECRAFT · SAHİPLİK</span>'
            . '<h1>Sahipliği talep et</h1><p>Talep otomatik sahiplik vermez; yetkili incelemesinden sonra sonuçlandırılır.</p></div></header>'
            . ($submitted ? '<div class="surface-notice" role="status">Sahiplik talebin incelemeye gönderildi.</div>' : '')
            . '<form class="surface-panel minecraft-claim-form" action="' . $action . '" method="post">'
            . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
            . '<h2>Doğrulama bilgisi</h2><label><span>Sunucuyla ilişkini ve doğrulanabilir kanıtı açıkla</span>'
            . '<textarea name="proof_note" minlength="20" maxlength="1000" rows="7" required></textarea></label>'
            . '<p class="surface-help">Hassas parola veya özel anahtar gönderme. Yetkilinin sahipliği doğrulayabileceği güvenli bilgiyi paylaş.</p>'
            . '<button class="fx-btn fx-btn--primary" type="submit">Talebi gönder</button></form>'
            . '<section class="surface-panel minecraft-claim-history"><h2>Talep geçmişin</h2>' . $history . '</section></section>';

        return ProfileHtml::page('Sunucu Sahipliği · ' . $server->name, $body, $basePath, authenticated:true);
    }

    /** @param list<MinecraftServerUpdate> $updates */
    private static function updateManagementRows(array $updates, string $action, string $csrf): string
    {
        if ($updates === []) {
            return '<div class="surface-empty"><strong>Henüz güncelleme yok.</strong>'
                . '<span>İlk güncelleme notunu üstteki formdan yayınlayabilirsin.</span></div>';
        }
        $rows = '';
        foreach ($updates as $update) {
            $targetState = $update->published() ? 'hidden' : 'published';
            $buttonLabel = $update->published() ? 'Gizle' : 'Yayınla';
            $stateLabel = $update->published() ? 'Yayında' : 'Gizli';
            $rows .= '<article class="minecraft-update-manage-row"><div><div><strong>' . self::e($update->title)
                . '</strong><span>' . self::e($stateLabel) . '</span></div><p>'
                . self::e(self::excerpt($update->body, 180)) . '</p><small>'
                . self::e($update->createdAt->format('d.m.Y H:i')) . '</small></div>'
                . '<form action="' . $action . '" method="post">'
                . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                . '<input type="hidden" name="action" value="update_state">'
                . '<input type="hidden" name="update_id" value="' . self::e($update->updateId->value()) . '">'
                . '<input type="hidden" name="update_state" value="' . self::e($targetState) . '">'
                . '<button class="fx-btn" type="submit">' . self::e($buttonLabel) . '</button></form></article>';
        }
        return $rows;
    }

    /**
     * @param list<MinecraftServerClaim> $claims
     * @param array<string,string> $claimantNames
     */
    private static function claimManagementRows(
        array $claims,
        array $claimantNames,
        BasePath $basePath,
        bool $withActions,
        string $csrf,
    ): string {
        $rows = '';
        foreach ($claims as $claim) {
            if (!$withActions && $claim->state !== 'pending') {
                continue;
            }
            $serverHref = self::e($basePath->prepend(
                '/servers/' . rawurlencode($claim->serverId->value()) . '/manage',
            ));
            $name = $claimantNames[$claim->claimantUserId->value()] ?? $claim->claimantUserId->value();
            $rows .= '<article class="minecraft-claim-review-row"><div class="minecraft-claim-review-copy"><div><strong>'
                . self::e($name) . '</strong><span>' . self::e(self::claimStateLabel($claim->state)) . '</span></div>'
                . '<p>' . self::e($claim->proofNote) . '</p><small>' . self::e($claim->createdAt->format('d.m.Y H:i'))
                . '</small></div>';
            if ($withActions && $claim->state === 'pending') {
                $rows .= '<form action="' . $serverHref . '" method="post" class="minecraft-claim-review-actions">'
                    . '<input type="hidden" name="_csrf" value="' . self::e($csrf) . '">'
                    . '<input type="hidden" name="action" value="claim_review">'
                    . '<input type="hidden" name="claim_id" value="' . self::e($claim->claimId->value()) . '">'
                    . '<label><span>İnceleme notu</span><input name="review_note" maxlength="1000"></label>'
                    . '<button class="fx-btn fx-btn--primary" name="decision" value="approve" type="submit">Onayla</button>'
                    . '<button class="fx-btn fx-btn--danger" name="decision" value="reject" type="submit">Reddet</button></form>';
            } elseif (!$withActions) {
                $rows .= '<a class="fx-btn" href="' . $serverHref . '">İncele</a>';
            }
            $rows .= '</article>';
        }
        return $rows;
    }

    private static function field(
        string $label,
        string $name,
        string $value,
        int $maxlength,
        string $type = 'text',
    ): string {
        $extra = $type === 'number' ? ' min="1" max="65535"' : '';
        return '<label><span>' . self::e($label) . '</span><input type="' . self::e($type) . '" name="'
            . self::e($name) . '" maxlength="' . $maxlength . '" value="' . self::e($value) . '"' . $extra . '></label>';
    }

    private static function option(string $value, string $label, string $current): string
    {
        return '<option value="' . self::e($value) . '"' . ($value === $current ? ' selected' : '') . '>'
            . self::e($label) . '</option>';
    }

    private static function listingStateLabel(string $state): string
    {
        return match ($state) {
            'published' => 'Yayında',
            'suspended' => 'Askıda',
            default => 'Taslak',
        };
    }

    private static function claimStateLabel(string $state): string
    {
        return match ($state) {
            'approved' => 'Onaylandı',
            'rejected' => 'Reddedildi',
            'cancelled' => 'İptal edildi',
            default => 'İncelemede',
        };
    }

    private static function seasonRow(MinecraftServerSeason $season): string
    {
        $state = match ($season->state) {
            'active' => 'Aktif',
            'upcoming' => 'Yaklaşan',
            default => 'Tamamlandı',
        };
        return '<article class="minecraft-season-row is-' . self::e($season->state) . '"><div>'
            . '<div class="minecraft-season-title"><strong>' . self::e($season->name)
            . '</strong><span>' . self::e($state) . '</span></div>'
            . '<p>' . self::e($season->summary) . '</p></div><div class="minecraft-season-meta">'
            . '<span>' . self::e($season->startsAt->format('d.m.Y')) . ' – '
            . self::e($season->endsAt->format('d.m.Y')) . '</span>'
            . '<strong>' . $season->serverCount . ' sunucu</strong></div></article>';
    }

    private static function seasonTab(
        string $label,
        ?string $value,
        ?string $active,
        BasePath $basePath,
    ): string {
        $path = '/servers/seasons' . ($value === null ? '' : '?state=' . rawurlencode($value));
        return '<a href="' . self::e($basePath->prepend($path)) . '"'
            . ($active === $value ? ' aria-current="page"' : '') . '>' . self::e($label) . '</a>';
    }

    private static function seasonPagination(
        int $page,
        bool $hasMore,
        ?string $state,
        BasePath $basePath,
    ): string {
        if ($page === 1 && !$hasMore) {
            return '';
        }
        $html = '<nav class="surface-pagination" aria-label="Sezon sayfaları">';
        if ($page > 1) {
            $html .= '<a href="' . self::e(self::seasonPageUrl($page - 1, $state, $basePath)) . '">← Önceki</a>';
        }
        $html .= '<span aria-current="page">Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a href="' . self::e(self::seasonPageUrl($page + 1, $state, $basePath)) . '">Sonraki →</a>';
        }
        return $html . '</nav>';
    }

    private static function seasonPageUrl(int $page, ?string $state, BasePath $basePath): string
    {
        $params = ['page'=>$page];
        if ($state !== null) {
            $params['state'] = $state;
        }
        return $basePath->prepend('/servers/seasons?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private static function updatePagination(
        MinecraftServer $server,
        int $page,
        bool $hasMore,
        BasePath $basePath,
    ): string {
        if ($page === 1 && !$hasMore) {
            return '';
        }
        $base = '/servers/' . rawurlencode($server->serverId->value()) . '/updates?page=';
        $html = '<nav class="surface-pagination" aria-label="Sunucu güncellemeleri sayfaları">';
        if ($page > 1) {
            $html .= '<a href="' . self::e($basePath->prepend($base . ($page - 1))) . '">← Önceki</a>';
        }
        $html .= '<span aria-current="page">Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a href="' . self::e($basePath->prepend($base . ($page + 1))) . '">Sonraki →</a>';
        }
        return $html . '</nav>';
    }

    private static function statCard(string $label, string $value): string
    {
        return '<article class="surface-panel minecraft-stat-card"><span>' . self::e($label)
            . '</span><strong>' . self::e($value) . '</strong></article>';
    }

    private static function shortDay(string $day): string
    {
        $parts = explode('-', $day);
        return count($parts) === 3 ? $parts[2] . '.' . $parts[1] : $day;
    }

    private static function excerpt(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1) . '…';
    }

    private static function directoryRow(MinecraftServer $server, BasePath $basePath): string
    {
        $href = self::e($basePath->prepend('/servers/' . rawurlencode($server->serverId->value())));
        $statusClass = ' is-' . $server->reachability;
        $verified = $server->verified() ? '<span class="minecraft-server-verified">Doğrulandı</span>' : '';
        return '<article class="minecraft-server-row' . $statusClass . '"><a href="' . $href . '">'
            . '<div class="minecraft-server-identity"><span class="minecraft-server-icon" aria-hidden="true">'
            . self::e(self::initial($server->name)) . '</span><div><div class="minecraft-server-title"><strong>'
            . self::e($server->name) . '</strong>' . $verified . '</div><p>' . self::e($server->summary) . '</p>'
            . '<small>' . self::e($server->address()) . '</small></div></div>'
            . '<div class="minecraft-server-meta"><span>' . self::e(strtoupper($server->edition)) . '</span><span>'
            . self::e($server->versionLabel !== '' ? $server->versionLabel : 'Sürüm yok') . '</span><span>'
            . self::e($server->gameMode !== '' ? $server->gameMode : 'Oyun modu yok') . '</span></div>'
            . '<div class="minecraft-server-state"><strong>' . self::e(self::players($server)) . '</strong><span>'
            . self::e(self::statusLabel($server)) . '</span></div></a></article>';
    }

    private static function tab(
        string $label,
        ?string $value,
        ?string $active,
        ?string $query,
        BasePath $basePath,
    ): string {
        $params = [];
        if ($value !== null) {
            $params['edition'] = $value;
        }
        if ($query !== null) {
            $params['q'] = $query;
        }
        $path = '/servers' . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $current = $active === $value ? ' aria-current="page"' : '';
        return '<a href="' . self::e($basePath->prepend($path)) . '"' . $current . '>' . self::e($label) . '</a>';
    }

    private static function pagination(
        int $page,
        bool $hasMore,
        ?string $query,
        ?string $edition,
        BasePath $basePath,
    ): string {
        if ($page === 1 && !$hasMore) {
            return '';
        }
        $html = '<nav class="surface-pagination" aria-label="Sunucu sayfaları">';
        if ($page > 1) {
            $html .= '<a href="' . self::e(self::pageUrl($page - 1, $query, $edition, $basePath)) . '">← Önceki</a>';
        }
        $html .= '<span aria-current="page">Sayfa ' . $page . '</span>';
        if ($hasMore) {
            $html .= '<a href="' . self::e(self::pageUrl($page + 1, $query, $edition, $basePath)) . '">Sonraki →</a>';
        }
        return $html . '</nav>';
    }

    private static function pageUrl(int $page, ?string $query, ?string $edition, BasePath $basePath): string
    {
        $params = ['page'=>$page];
        if ($edition !== null) {
            $params['edition'] = $edition;
        }
        if ($query !== null) {
            $params['q'] = $query;
        }
        return $basePath->prepend('/servers?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }

    private static function statusLabel(MinecraftServer $server): string
    {
        return match ($server->reachability) {
            'online' => 'Çevrimiçi',
            'offline' => 'Çevrimdışı',
            default => 'Durum bilinmiyor',
        };
    }

    private static function players(MinecraftServer $server): string
    {
        if ($server->onlinePlayers === null || $server->maxPlayers === null) {
            return '—';
        }
        return $server->onlinePlayers . ' / ' . $server->maxPlayers;
    }

    private static function fact(string $label, string $value): string
    {
        return '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
    }

    private static function safeExternal(?string $url): ?string
    {
        if ($url === null || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http','https'], true) ? $url : null;
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
