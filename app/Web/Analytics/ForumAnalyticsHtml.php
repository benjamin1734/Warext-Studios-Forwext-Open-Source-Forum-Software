<?php

declare(strict_types=1);

namespace Forwext\App\Web\Analytics;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Analytics\Dashboard\ForumAnalyticsSnapshot;
use Forwext\Core\Routing\BasePath;

final class ForumAnalyticsHtml
{
    public static function page(ForumAnalyticsSnapshot $snapshot, BasePath $basePath): string
    {
        $base = self::e($basePath->prepend('/admin/analytics'));
        $content = self::e($basePath->prepend('/admin/analytics/content'));
        $operations = self::e($basePath->prepend('/admin/analytics/operations'));
        $commerce = self::e($basePath->prepend('/admin/analytics/commerce'));
        $body = '<section class="card"><h1 style="margin-top:0">Forum Analiz Dashboardu</h1>'
            . '<p class="muted">Site geneli büyüme, aktif kullanıcı ve içerik üretim metrikleri. Saatler UTC tabanlıdır.</p>'
            . '<div class="market-actions">';
        $body .= '<a href="'.$content.'">İçerik & engagement</a>';
        $body .= '<a href="'.$operations.'">Moderasyon & operasyon</a>';
        $body .= '<a href="'.$commerce.'">Marketplace & gelir</a>';
        foreach ([7,30,90] as $days) {
            $label = $days . ' gün';
            $style = $snapshot->windowDays === $days ? ' style="font-weight:700"' : '';
            $body .= '<a'.$style.' href="'.$base.'?days='.$days.'">'.self::e($label).'</a>';
        }
        $body .= '</div></section>';

        $body .= '<div class="stats-grid" style="margin-top:16px">'
            . self::stat('DAU', $snapshot->dau, 'Bugün benzersiz aktif hesap')
            . self::stat('MAU', $snapshot->mau, 'Son 30 gün benzersiz aktif hesap')
            . self::stat('Aktif hesap', $snapshot->activeAccounts, 'Şu an active durumundaki hesaplar')
            . self::stat('Çevrimiçi', $snapshot->onlineNow, 'Son 5 dakikada presence')
            . self::stat('24s online peak', $snapshot->onlinePeak24h, '5 dakikalık benzersiz aktif bucket')
            . self::stat('7g online peak', $snapshot->onlinePeak7d, '5 dakikalık benzersiz aktif bucket')
            . self::stat('7g aktivite retention', self::rate($snapshot->retention7), '7 gün önce aktif olup bugün yeniden aktif olanlar')
            . self::stat('30g aktivite retention', self::rate($snapshot->retention30), '30 gün önce aktif olup bugün yeniden aktif olanlar')
            . '</div>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Büyüme</h2>'
            . '<div class="stats-grid">'
            . self::growth('Kayıtlar', $snapshot->registrationsWindow, $snapshot->registrationsPreviousWindow)
            . self::growth('Konular', $snapshot->threadsWindow, $snapshot->threadsPreviousWindow)
            . self::growth('Mesajlar', $snapshot->postsWindow, $snapshot->postsPreviousWindow)
            . self::stat('Bugünkü kayıt', $snapshot->registrationsToday, 'UTC gün')
            . self::stat('Toplam konu', $snapshot->threadsTotal, 'Görünür, silinmemiş, birleştirilmemiş')
            . self::stat('Toplam mesaj', $snapshot->postsTotal, 'Görünür ve silinmemiş içerik')
            . '</div></section>';

        $body .= '<section class="card" style="margin-top:16px;overflow:auto"><h2>Günlük trend</h2>'
            . '<p class="muted">Kayıt/konu/mesaj sayıları authoritative domain tablolarından; aktif kullanıcı sayısı pseudonymous analytics eventlerinden hesaplanır.</p>'
            . '<table style="width:100%;border-collapse:collapse"><thead><tr>'
            . '<th style="text-align:left">Gün (UTC)</th><th>Kayıt</th><th>Konu</th><th>Mesaj</th><th>Aktif kullanıcı</th>'
            . '</tr></thead><tbody>';
        foreach ($snapshot->daily as $row) {
            $body .= '<tr><td>'.self::e($row->day->format('Y-m-d')).'</td>'
                . '<td style="text-align:center">'.self::n($row->registrations).'</td>'
                . '<td style="text-align:center">'.self::n($row->threads).'</td>'
                . '<td style="text-align:center">'.self::n($row->posts).'</td>'
                . '<td style="text-align:center">'.self::n($row->activeUsers).'</td></tr>';
        }
        $body .= '</tbody></table></section>';

        $body .= '<section class="card" style="margin-top:16px"><h2>Veri sınırları</h2>'
            . '<p class="muted">DAU/MAU, peak ve aktivite retention metrikleri ham kullanıcı kimliği yerine installation-specific HMAC actor hash kullanır. '
            . 'Kayıt, konu ve mesaj sayıları analytics event kaybından etkilenmemesi için doğrudan domain tablolarından okunur. '
            . 'Bu ekran site-geneli BI izni olmadan açılamaz.</p>'
            . '<p class="muted">Üretildi: '.self::e($snapshot->generatedAt->format('Y-m-d H:i:s')).' UTC</p></section>';

        return ProfileHtml::page('Forum Analiz Dashboardu', $body, $basePath, authenticated:true);
    }

    private static function growth(string $label, int $current, int $previous): string
    {
        $growth = ForumAnalyticsSnapshot::growthPercent($current, $previous);
        $text = $growth === null ? 'Yeni baz' : number_format($growth, 1, ',', '.') . '%';
        return self::stat($label, self::n($current), 'Önceki eşit dönem: '.self::n($previous).' · değişim '.$text);
    }

    private static function stat(string $label, int|string $value, string $hint): string
    {
        $display = is_int($value) ? self::n($value) : $value;
        return '<div class="card stat"><span class="muted">'.self::e($label).'</span>'
            . '<strong>'.self::e($display).'</strong><small class="muted">'.self::e($hint).'</small></div>';
    }

    private static function rate(?float $rate): string
    {
        return $rate === null ? '—' : number_format($rate, 1, ',', '.') . '%';
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
