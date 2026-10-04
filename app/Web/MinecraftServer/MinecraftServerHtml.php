<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Minecraft\Server\MinecraftServer;
use Forwext\Core\Minecraft\Server\MinecraftServerSeason;
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

    public static function detail(MinecraftServer $server, BasePath $basePath, bool $authenticated): string
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

        $body = '<section class="minecraft-server-page minecraft-server-detail discovery-page">'
            . '<header class="surface-head minecraft-server-detail-head"><div>'
            . '<a class="surface-back-link" href="' . self::e($basePath->prepend('/servers')) . '">← Sunucular</a>'
            . '<span class="forum-eyebrow">' . self::e(strtoupper($server->edition)) . ' · '
            . self::e($server->versionLabel !== '' ? $server->versionLabel : 'Sürüm belirtilmedi') . '</span>'
            . '<h1>' . self::e($server->name) . '</h1><p>' . self::e($server->summary) . '</p></div>'
            . '<div class="minecraft-server-detail-actions">' . $verified . $links . '</div></header>'
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
