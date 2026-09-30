<?php

declare(strict_types=1);

namespace Forwext\App\Web\Referral;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Referral\ReferralAnalytics;
use Forwext\Core\Referral\ReferralAttribution;
use Forwext\Core\Referral\ReferralCampaign;
use Forwext\Core\Referral\ReferralLink;
use Forwext\Core\Referral\ReferralReward;
use Forwext\Core\Routing\BasePath;

final class ReferralHtml
{
    /**
     * @param list<ReferralCampaign> $campaigns
     * @param list<ReferralLink> $links
     * @param list<ReferralReward> $rewards
     */
    public static function account(
        array $campaigns,
        array $links,
        array $rewards,
        ReferralAnalytics $analytics,
        BasePath $basePath,
        string $canonicalUrl,
        string $csrfToken,
        bool $updated,
        bool $canManage,
    ): string {
        $linkByCampaign = [];
        foreach ($links as $link) {
            $linkByCampaign[$link->campaignId->value()] = $link;
        }

        $manage = $canManage
            ? '<a class="fx-btn" href="' . self::e($basePath->prepend('/referrals/manage'))
                . '">Referans yönetimi</a>'
            : '';

        $body = '<section class="referral-account discovery-page"><header class="surface-head referral-head"><div>'
            . '<span class="forum-eyebrow">DAVETLER</span><h1>Davetlerim ve Referanslarım</h1>'
            . '<p>Davet bağlantılarını yönet, nitelikli referanslarını ve ödül durumunu takip et.</p></div>'
            . $manage . '</header>';

        if ($updated) {
            $body .= '<div class="notification-settings-notice" role="status">Davet bağlantın hazır.</div>';
        }

        $body .= '<section class="referral-stats" aria-label="Referans özeti">'
            . self::accountStat('Tıklama', $analytics->clicks)
            . self::accountStat('Attribution', $analytics->attributed)
            . self::accountStat('Nitelikli', $analytics->qualified)
            . self::accountStat('İnceleme', $analytics->review)
            . self::accountStat('Reddedilen', $analytics->rejected)
            . self::accountStat('Ödül Birimi', $analytics->rewardUnits)
            . '</section>';

        $body .= '<section class="surface-panel referral-panel"><header><h2>Aktif kampanyalar</h2><span>'
            . count($campaigns) . '</span></header><div class="referral-campaign-list">';
        if ($campaigns === []) {
            $body .= '<div class="surface-empty"><strong>Aktif kampanya yok.</strong>'
                . '<span>Yeni bir davet kampanyası açıldığında burada görünecek.</span></div>';
        } else {
            foreach ($campaigns as $campaign) {
                $link = $linkByCampaign[$campaign->campaignId->value()] ?? null;
                $body .= '<article class="referral-campaign"><div class="referral-campaign-copy">'
                    . '<span class="referral-campaign-key">' . self::e($campaign->key) . '</span>'
                    . '<h3>' . self::e($campaign->name) . '</h3>'
                    . '<p>Nitelik bekleme · ' . self::e(self::duration($campaign->qualificationDelaySeconds))
                    . ' · Attribution · ' . self::e(self::duration($campaign->attributionWindowSeconds))
                    . ' · Ödül · ' . $campaign->rewardUnits . ' ' . self::e($campaign->rewardKey) . '</p></div>';
                if ($link !== null && $link->isAvailable(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                    $share = rtrim($canonicalUrl, '/') . '/ref/' . rawurlencode($link->code);
                    $body .= '<label class="referral-link"><span>Davet bağlantın</span>'
                        . '<input readonly value="' . self::e($share) . '" aria-label="Davet bağlantısı"></label>';
                } else {
                    $body .= '<form method="post" action="' . self::e($basePath->prepend('/account/referrals'))
                        . '" class="referral-link-create">' . self::csrf($csrfToken)
                        . '<input type="hidden" name="action" value="create_link">'
                        . '<input type="hidden" name="campaign_id" value="' . self::e($campaign->campaignId->value()) . '">'
                        . '<button class="fx-btn fx-btn--primary" type="submit">Bağlantı oluştur</button></form>';
                }
                $body .= '</article>';
            }
        }
        $body .= '</div></section>';

        $body .= '<section class="surface-panel referral-panel"><header><h2>Ödül geçmişi</h2><span>'
            . count($rewards) . '</span></header><div class="referral-reward-list">';
        if ($rewards === []) {
            $body .= '<div class="surface-empty"><strong>Henüz ödül yok.</strong>'
                . '<span>Nitelikli referans ödüllerin burada listelenecek.</span></div>';
        } else {
            foreach ($rewards as $reward) {
                $body .= '<article class="referral-reward-row"><strong>' . $reward->units . ' '
                    . self::e($reward->rewardKey) . '</strong><span>'
                    . self::e($reward->state->value) . ' · '
                    . self::e($reward->grantedAt->format('Y-m-d H:i')) . ' UTC</span></article>';
            }
        }
        $body .= '</div></section></section>';

        return ProfileHtml::page('Davetlerim ve Referanslarım', $body, $basePath, authenticated:true);
    }


    /**
     * @param list<ReferralCampaign> $campaigns
     * @param list<ReferralAttribution> $review
     */
    public static function manage(
        array $campaigns,
        array $review,
        ReferralAnalytics $analytics,
        BasePath $basePath,
        string $csrfToken,
        bool $updated,
    ): string {
        $action = self::e($basePath->prepend('/referrals/manage'));
        $body = '<section class="card"><h1>Referans Yönetimi</h1>'
            . '<p class="muted">Kampanya, attribution, anti-fraud inceleme ve qualification işlemleri.</p>';
        if ($updated) {
            $body .= '<div class="notice success">Değişiklik kaydedildi.</div>';
        }
        $body .= self::stats($analytics)
            . '<form method="post" action="' . $action . '" class="presence-settings">'
            . self::csrf($csrfToken) . '<input type="hidden" name="action" value="qualify_due">'
            . '<button type="submit">Bekleyen nitelikleri şimdi işle</button></form>'
            . '<section class="section"><h2>Kampanyalar</h2>';

        $editable = array_merge([null], $campaigns);
        foreach ($editable as $campaign) {
            $isNew = $campaign === null;
            $body .= '<details class="search-hit"' . ($isNew ? ' open' : '') . '><summary>'
                . ($isNew ? 'Yeni kampanya' : self::e($campaign->name)) . '</summary>'
                . '<form method="post" action="' . $action . '" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="campaign_save">'
                . '<input type="hidden" name="campaign_id" value="' . self::e($campaign?->campaignId->value() ?? '') . '">'
                . '<label><span>Anahtar</span><input name="key" maxlength="64" required value="'
                . self::e($campaign?->key ?? '') . '"></label>'
                . '<label><span>Ad</span><input name="name" maxlength="120" required value="'
                . self::e($campaign?->name ?? '') . '"></label>'
                . '<label><span>Başlangıç (UTC)</span><input type="datetime-local" name="starts_at" required value="'
                . self::e($campaign?->startsAt->format('Y-m-d\TH:i') ?? gmdate('Y-m-d\TH:i')) . '"></label>'
                . '<label><span>Bitiş (UTC, opsiyonel)</span><input type="datetime-local" name="ends_at" value="'
                . self::e($campaign?->endsAt?->format('Y-m-d\TH:i') ?? '') . '"></label>'
                . '<label><span>Nitelik bekleme (sn)</span><input type="number" min="0" max="31536000" '
                . 'name="qualification_delay" value="' . ($campaign?->qualificationDelaySeconds ?? 86400) . '"></label>'
                . '<label><span>Attribution penceresi (sn)</span><input type="number" min="3600" max="31536000" '
                . 'name="attribution_window" value="' . ($campaign?->attributionWindowSeconds ?? 2592000) . '"></label>'
                . '<label><span>Aynı ağ limiti</span><input type="number" min="1" max="100" name="network_limit" value="'
                . ($campaign?->duplicateNetworkLimit ?? 1) . '"></label>'
                . '<label><span>Aynı cihaz limiti</span><input type="number" min="1" max="100" name="device_limit" value="'
                . ($campaign?->duplicateDeviceLimit ?? 1) . '"></label>'
                . '<label><span>Kullanıcı başı max nitelikli</span><input type="number" min="1" max="1000000" '
                . 'name="max_qualified" value="' . self::e($campaign?->maxQualifiedPerReferrer === null
                    ? '' : (string) $campaign->maxQualifiedPerReferrer) . '"></label>'
                . '<label><span>Ödül anahtarı</span><input name="reward_key" maxlength="64" required value="'
                . self::e($campaign?->rewardKey ?? 'referral.credit') . '"></label>'
                . '<label><span>Ödül miktarı</span><input type="number" min="1" max="1000000000" name="reward_units" value="'
                . ($campaign?->rewardUnits ?? 1) . '"></label>'
                . '<label><input type="checkbox" name="active" value="1"'
                . ($campaign?->active ? ' checked' : '') . '> Aktif</label>'
                . '<div class="search-actions"><button type="submit">Kampanyayı kaydet</button></div></form></details>';
        }

        $body .= '</section><section class="section"><h2>Anti-fraud inceleme kuyruğu</h2>';
        if ($review === []) {
            $body .= '<p class="muted">İnceleme bekleyen attribution yok.</p>';
        } else {
            foreach ($review as $item) {
                $body .= '<article class="search-hit"><strong>' . self::e($item->attributionId->value()) . '</strong>'
                    . '<div class="muted">Risk: ' . self::e($item->riskCode ?? 'manual_review')
                    . ' · Referrer: ' . self::e($item->referrerUserId->value())
                    . ' · Referred: ' . self::e($item->referredUserId->value()) . '</div>'
                    . '<form method="post" action="' . $action . '" class="presence-settings">'
                    . self::csrf($csrfToken)
                    . '<input type="hidden" name="action" value="review">'
                    . '<input type="hidden" name="attribution_id" value="' . self::e($item->attributionId->value()) . '">'
                    . '<button type="submit" name="decision" value="approve">Onayla</button>'
                    . '<button type="submit" name="decision" value="reject">Reddet</button></form></article>';
            }
        }
        $body .= '</section></section>';

        return ProfileHtml::page('Referans Yönetimi', $body, $basePath, authenticated:true);
    }

    private static function accountStat(string $label, int $value): string
    {
        return '<div class="referral-stat"><strong>' . $value . '</strong><span>' . self::e($label) . '</span></div>';
    }

    private static function stats(ReferralAnalytics $analytics): string
    {
        return '<div class="stats-grid section">'
            . self::stat('Tıklama', $analytics->clicks)
            . self::stat('Attribution', $analytics->attributed)
            . self::stat('Nitelikli', $analytics->qualified)
            . self::stat('İnceleme', $analytics->review)
            . self::stat('Reddedilen', $analytics->rejected)
            . self::stat('Ödül Birimi', $analytics->rewardUnits)
            . '</div>';
    }

    private static function stat(string $label, int $value): string
    {
        return '<div class="card stat"><strong>' . $value . '</strong><span class="muted">' . self::e($label) . '</span></div>';
    }

    private static function duration(int $seconds): string
    {
        if ($seconds % 86400 === 0) return (string) ($seconds / 86400) . ' gün';
        if ($seconds % 3600 === 0) return (string) ($seconds / 3600) . ' saat';
        return $seconds . ' sn';
    }

    private static function csrf(string $token): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::e($token) . '">';
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
