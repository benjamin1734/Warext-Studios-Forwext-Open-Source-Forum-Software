<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Analytics\Commerce\CommerceAnalyticsSnapshot;
use Forwext\Core\Routing\BasePath;

final class CommerceAnalyticsHtml
{
    public static function page(CommerceAnalyticsSnapshot $snapshot, BasePath $basePath): string
    {
        $base = self::e($basePath->prepend('/admin/analytics/commerce'));
        $overview = self::e($basePath->prepend('/admin/analytics'));
        $content = self::e($basePath->prepend('/admin/analytics/content'));
        $operations = self::e($basePath->prepend('/admin/analytics/operations'));

        $body = '<section class="card"><h1 style="margin-top:0">Marketplace, Gelir, Referral ve Giveaway Analizleri</h1>'
            . '<p class="muted">Marketplace funnel, para akışı, external yönlendirme, referral conversion ve giveaway participation. '
            . 'Tüm dönemler UTC tabanlıdır.</p>'
            . '<div class="market-actions"><a href="'.$overview.'">Forum genel dashboardu</a>'
            . '<a href="'.$content.'">İçerik & engagement</a>'
            . '<a href="'.$operations.'">Moderasyon & operasyon</a>';
        foreach ([7, 30, 90] as $days) {
            $style = $snapshot->windowDays === $days ? ' style="font-weight:700"' : '';
            $body .= '<a'.$style.' href="'.$base.'?days='.$days.'">'.$days.' gün</a>';
        }
        $body .= '</div></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Marketplace funnel</h2><div class="stats-grid">'
            . self::stat('Yeni ilan', $snapshot->listingsCreated, 'Dönemde oluşturulan listing')
            . self::stat('Aktif ilan', $snapshot->activeListings, 'Şu an active durumda')
            . self::stat('Listing view', $snapshot->listingViews, '15.05 producer sonrası kaydedilen görünüm')
            . self::stat('External click', $snapshot->externalClicks, 'Dönemde outbound satış yönlendirmesi')
            . self::stat('External CTR', self::rate($snapshot->externalCtr), 'External click / listing view')
            . self::stat('Yeni order', $snapshot->ordersCreated, 'Dönemde oluşturulan')
            . self::stat('Paid transition', $snapshot->ordersPaid, 'İlk kez paid durumuna geçen order')
            . self::stat('Completed', $snapshot->ordersCompleted, 'Dönemde completed durumuna geçen')
            . self::stat('Cancelled', $snapshot->ordersCancelled, 'Dönemde cancelled durumuna geçen')
            . '</div></section>';

        $body .= self::moneyTable($snapshot);
        $body .= self::advertisingTable($snapshot);

        $body .= '<section class="card" style="margin-top:16px"><h2>Referral funnel</h2><div class="stats-grid">'
            . self::stat('Referral click', $snapshot->referralClicks, 'Dönemde link click')
            . self::stat('Attributed', $snapshot->referralAttributed, 'Dönemde oluşturulan attribution cohort')
            . self::stat('Review', $snapshot->referralReview, 'Cohort içinde mevcut review')
            . self::stat('Qualified', $snapshot->referralQualified, 'Cohort içinde mevcut qualified')
            . self::stat('Rejected', $snapshot->referralRejected, 'Cohort içinde mevcut rejected')
            . self::stat('Ödül birimi', $snapshot->referralRewardUnits, 'Dönemde granted reward units')
            . self::stat('Click → attribution', self::rate($snapshot->referralAttributionConversion), 'Dönem conversion')
            . self::stat('Attribution → qualified', self::rate($snapshot->referralQualifiedConversion), 'Cohort conversion')
            . '</div></section>';

        $body .= self::referralTable($snapshot);

        $body .= '<section class="card" style="margin-top:16px"><h2>Giveaway participation</h2><div class="stats-grid">'
            . self::stat('Yeni giveaway', $snapshot->giveawaysCreated, 'Dönemde oluşturulan')
            . self::stat('Katılımcı', $snapshot->giveawayParticipants, 'Dönemde unique user')
            . self::stat('Entry weight', $snapshot->giveawayEntries, 'Entry_count toplamı')
            . self::stat('Draw', $snapshot->giveawayDraws, 'Dönemde draw/redraw')
            . '</div></section>';

        $body .= self::giveawayTable($snapshot);

        $body .= '<section class="card" style="margin-top:16px"><h2>Veri sınırları</h2>'
            . '<p class="muted">Para değerleri hiçbir zaman farklı currency kodları arasında toplanmaz. Marketplace GMV, order history içindeki '
            . 'ilk paid transition ile ilişkilendirilen order total değeridir. Refund satırı dönem içinde succeeded olan refundları gösterir; '
            . 'net payment flow = GMV - refund olabilir ve eski dönem satışlarına yapılan iadeler nedeniyle negatif olabilir. Bu metrik platform kârı değildir.</p>'
            . '<p class="muted">Advertising geliri gerçek settlement değildir; kampanyalarda tanımlı impression/click değerlerinden türetilen estimated değerdir. '
            . 'External CTR denominatorü yalnız 15.05 ile eklenen marketplace listing view eventlerinden oluşur ve önceki trafik için backfill yapılmaz.</p>'
            . '<p class="muted">Referral analytics IP/device fingerprintleri okumaz; yalnız kampanya, state, click ve reward metadata kullanır. '
            . 'Giveaway analytics network/device fingerprintleri kullanmaz.</p>'
            . '<p class="muted">Üretildi: '.self::e($snapshot->generatedAt->format('Y-m-d H:i:s')).' UTC</p></section>';

        return ProfileHtml::page(
            'Marketplace, Gelir, Referral ve Giveaway Analizleri',
            $body,
            $basePath,
            authenticated: true,
        );
    }

    private static function moneyTable(CommerceAnalyticsSnapshot $snapshot): string
    {
        $body = '<section class="card" style="margin-top:16px;overflow:auto"><h2>Marketplace GMV ve payment flow</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Currency</th><th>Paid order</th><th>GMV</th><th>Refund</th><th>Net payment flow</th>'
            . '</tr></thead><tbody>';
        if ($snapshot->marketplaceMoney === []) {
            $body .= '<tr><td colspan="5" class="muted">Para akışı verisi yok.</td></tr>';
        }
        foreach ($snapshot->marketplaceMoney as $row) {
            $body .= '<tr><td>'.self::e($row['currency']).'</td>'
                . '<td>'.self::n($row['paid_orders']).'</td>'
                . '<td>'.self::money($row['gmv_minor'], $row['currency']).'</td>'
                . '<td>'.self::money($row['refund_minor'], $row['currency']).'</td>'
                . '<td>'.self::money($row['net_payment_flow_minor'], $row['currency']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function advertisingTable(CommerceAnalyticsSnapshot $snapshot): string
    {
        $body = '<section class="card" style="margin-top:16px;overflow:auto"><h2>Advertising estimated revenue</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Currency</th><th>Impression</th><th>Click</th><th>CTR</th><th>Estimated revenue</th>'
            . '</tr></thead><tbody>';
        if ($snapshot->advertisingRevenue === []) {
            $body .= '<tr><td colspan="5" class="muted">Advertising event verisi yok.</td></tr>';
        }
        foreach ($snapshot->advertisingRevenue as $row) {
            $body .= '<tr><td>'.self::e($row['currency']).'</td>'
                . '<td>'.self::n($row['impressions']).'</td><td>'.self::n($row['clicks']).'</td>'
                . '<td>'.self::rate($row['ctr']).'</td>'
                . '<td>'.self::money($row['estimated_revenue_minor'], $row['currency']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function referralTable(CommerceAnalyticsSnapshot $snapshot): string
    {
        $body = '<section class="card" style="margin-top:16px;overflow:auto"><h2>Referral campaign conversion</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Campaign</th><th>Click</th><th>Attributed</th><th>Review</th><th>Qualified</th>'
            . '<th>Rejected</th><th>Reward units</th><th>Click→Attr.</th><th>Attr.→Qualified</th>'
            . '</tr></thead><tbody>';
        if ($snapshot->referralCampaigns === []) {
            $body .= '<tr><td colspan="9" class="muted">Referral campaign verisi yok.</td></tr>';
        }
        foreach ($snapshot->referralCampaigns as $row) {
            $body .= '<tr><td>'.self::e($row['name'].' · '.$row['campaign_key']).'</td>'
                . '<td>'.self::n($row['clicks']).'</td><td>'.self::n($row['attributed']).'</td>'
                . '<td>'.self::n($row['review']).'</td><td>'.self::n($row['qualified']).'</td>'
                . '<td>'.self::n($row['rejected']).'</td><td>'.self::n($row['reward_units']).'</td>'
                . '<td>'.self::rate($row['click_to_attribution']).'</td>'
                . '<td>'.self::rate($row['attribution_to_qualified']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function giveawayTable(CommerceAnalyticsSnapshot $snapshot): string
    {
        $body = '<section class="card" style="margin-top:16px;overflow:auto"><h2>Giveaway bazlı participation</h2>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Giveaway</th><th>State</th><th>Katılımcı</th><th>Entry weight</th><th>Draw</th>'
            . '</tr></thead><tbody>';
        if ($snapshot->giveaways === []) {
            $body .= '<tr><td colspan="5" class="muted">Giveaway participation verisi yok.</td></tr>';
        }
        foreach ($snapshot->giveaways as $row) {
            $body .= '<tr><td>'.self::e($row['title']).'</td><td>'.self::e($row['state']).'</td>'
                . '<td>'.self::n($row['participants']).'</td><td>'.self::n($row['entries']).'</td>'
                . '<td>'.self::n($row['draws']).'</td></tr>';
        }
        return $body.'</tbody></table></section>';
    }

    private static function stat(string $label, int|string $value, string $hint): string
    {
        $display = is_int($value) ? self::n($value) : $value;
        return '<div class="card stat"><span class="muted">'.self::e($label).'</span>'
            . '<strong>'.self::e($display).'</strong><small class="muted">'.self::e($hint).'</small></div>';
    }

    private static function money(int $minor, string $currency): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $value = number_format($absolute / 100, 2, ',', '.');
        return self::e(($negative ? '-' : '').$value.' '.$currency);
    }

    private static function rate(?float $rate): string
    {
        return $rate === null ? '—' : self::e(number_format($rate, 1, ',', '.').'%');
    }

    private static function n(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private static function e(string $value): string
    {
        return ProfileHtml::escape($value);
    }
}
