<?php

declare(strict_types=1);

namespace Forwext\App\Web\Giveaway;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Giveaway\Giveaway;
use Forwext\Core\Giveaway\GiveawayDrawProof;
use Forwext\Core\Giveaway\GiveawayEligibilityDecision;
use Forwext\Core\Giveaway\GiveawayEligibilityPolicy;
use Forwext\Core\Giveaway\GiveawayEligibilityRoleOption;
use Forwext\Core\Giveaway\GiveawayEntry;
use Forwext\Core\Giveaway\GiveawayReferralRequirement;
use Forwext\Core\Giveaway\GiveawayState;
use Forwext\Core\Routing\BasePath;

final class GiveawayHtml
{
    /** @param list<Giveaway> $giveaways */
    public static function index(
        array $giveaways,
        BasePath $basePath,
        bool $canCreate,
    ): string {
        $actions = $canCreate
            ? '<a class="fx-btn fx-btn--primary" href="' . self::e($basePath->prepend('/giveaways/manage'))
                . '">Yeni çekiliş</a>'
            : '';

        $body = '<section class="giveaway-index discovery-page"><header class="surface-head giveaway-head"><div>'
            . '<span class="forum-eyebrow">TOPLULUK</span><h1>Çekilişler</h1>'
            . '<p>Aktif, planlanmış ve tamamlanmış topluluk çekilişlerini takip et.</p></div>'
            . $actions . '</header>';

        if ($giveaways === []) {
            $body .= '<section class="surface-panel giveaway-index-panel"><div class="surface-empty">'
                . '<strong>Görüntülenebilir çekiliş yok.</strong>'
                . '<span>Yeni bir çekiliş yayımlandığında burada görünecek.</span></div></section>';
        } else {
            $body .= '<section class="surface-panel giveaway-index-panel"><div class="giveaway-grid">';
            foreach ($giveaways as $giveaway) {
                $body .= self::card($giveaway, $basePath);
            }
            $body .= '</div></section>';
        }
        $body .= '</section>';

        return ProfileHtml::page('Çekilişler', $body, $basePath, authenticated:true);
    }

    public static function detail(
        Giveaway $giveaway,
        GiveawayEligibilityPolicy $policy,
        ?GiveawayEntry $entry,
        ?GiveawayEligibilityDecision $decision,
        BasePath $basePath,
        bool $canManage,
        bool $canEnter,
        ?string $csrfToken,
        bool $entered,
        ?string $entryError,
    ): string {
        $manage = $canManage
            ? '<a class="fx-btn" href="' . self::e($basePath->prepend(
                '/giveaways/manage?giveaway=' . rawurlencode($giveaway->giveawayId->value()),
            )) . '">Çekilişi yönet</a>'
            : '';

        $body = '<article class="giveaway-detail discovery-page"><header class="surface-head giveaway-detail-head"><div>'
            . '<span class="forum-eyebrow">' . self::e(self::stateLabel($giveaway->state)) . '</span>'
            . '<h1>' . self::e($giveaway->title) . '</h1>'
            . '<p>' . self::e(self::date($giveaway->startsAt)) . ' → ' . self::e(self::date($giveaway->endsAt))
            . '</p></div>' . $manage . '</header>';

        if ($entered) {
            $body .= '<div class="notification-settings-notice" role="status">Çekiliş katılımın kaydedildi.</div>';
        } elseif ($entryError !== null) {
            $body .= '<div class="auth-entry-error" role="alert">' . self::e(self::eligibilityReason($entryError)) . '</div>';
        }

        $body .= '<div class="giveaway-detail-grid"><section class="surface-panel giveaway-prize"><span>ÖDÜL</span>'
            . '<h2>' . self::e($giveaway->prize->title) . '</h2><strong>× ' . $giveaway->prize->quantity . '</strong>';
        if ($giveaway->prize->description !== '') {
            $body .= '<p>' . nl2br(self::e($giveaway->prize->description), false) . '</p>';
        }
        $body .= '</section><section class="surface-panel giveaway-description"><h2>Açıklama</h2><div class="about">'
            . nl2br(self::e($giveaway->description), false) . '</div></section></div>';

        $body .= '<section class="surface-panel giveaway-eligibility"><header><div><h2>Katılım koşulları</h2>'
            . '<p>Kişi başı ' . $giveaway->entriesPerUser . ' hak · '
            . ($giveaway->maxParticipants === null ? 'Katılımcı sınırı yok' : 'En fazla ' . $giveaway->maxParticipants . ' katılımcı')
            . '</p></div></header><div class="giveaway-terms"><div class="about">'
            . nl2br(self::e($giveaway->participationTerms), false) . '</div><dl>'
            . '<div><dt>Minimum hesap yaşı</dt><dd>' . $policy->minAccountAgeDays . ' gün</dd></div>'
            . '<div><dt>Minimum görünür mesaj</dt><dd>' . $policy->minPostCount . '</dd></div>'
            . '<div><dt>Doğrulanmış hesap</dt><dd>' . ($policy->requireVerifiedAccount ? 'Gerekli' : 'Zorunlu değil') . '</dd></div>'
            . '<div><dt>Rol koşulu</dt><dd>' . ($policy->allowedRoleIds === [] ? 'Yok' : count($policy->allowedRoleIds) . ' izinli rolden biri') . '</dd></div>'
            . '<div><dt>Referral koşulu</dt><dd>' . self::e(self::referralLabel($policy)) . '</dd></div>'
            . '</dl></div>';

        $participation = '';
        if ($entry !== null) {
            $participation = '<div class="giveaway-entry-state is-success"><strong>Katılım aktif</strong><span>'
                . $entry->entryCount . ' hak</span></div>';
        } elseif (!$canEnter) {
            $participation = '<div class="giveaway-entry-state"><strong>Katılım kullanılamıyor</strong>'
                . '<span>Hesabının bu çekilişe katılma izni yok.</span></div>';
        } elseif ($decision !== null && !$decision->eligible) {
            $labels = array_map(self::eligibilityReason(...), $decision->reasons);
            $participation = '<div class="giveaway-entry-state is-error"><strong>Şu anda uygun değilsin</strong>'
                . '<span>' . self::e(implode(' · ', $labels)) . '</span></div>';
        } elseif ($giveaway->state === GiveawayState::Open && $csrfToken !== null) {
            $participation = '<form method="post" action="' . self::e($basePath->prepend(
                '/giveaways/' . rawurlencode($giveaway->giveawayId->value()) . '/enter',
            )) . '" class="giveaway-entry-form">' . self::csrf($csrfToken)
                . '<button class="fx-btn fx-btn--primary" type="submit">Çekilişe katıl</button></form>';
        }
        if ($participation !== '') {
            $body .= '<div class="giveaway-participation">' . $participation . '</div>';
        }
        $body .= '</section>';

        if ($giveaway->state === GiveawayState::Closed) {
            $body .= '<section class="surface-panel giveaway-proof-link"><div><h2>Kazanan seçimi</h2>'
                . '<p>Kriptografik seçim kaydını ve doğrulama zincirini inceleyebilirsin.</p></div>'
                . '<a class="fx-btn" href="' . self::e($basePath->prepend(
                    '/giveaways/' . rawurlencode($giveaway->giveawayId->value()) . '/proof',
                )) . '">Seçim kanıtını aç</a></section>';
        }

        $body .= '</article>';

        return ProfileHtml::page($giveaway->title, $body, $basePath, authenticated:true);
    }

    /** @param list<Giveaway> $giveaways */
    /**
     * @param list<Giveaway> $giveaways
     * @param list<GiveawayEligibilityRoleOption> $roleOptions
     * @param list<GiveawayDrawProof> $drawProofs
     */
    public static function manage(
        array $giveaways,
        ?Giveaway $giveaway,
        ?GiveawayEligibilityPolicy $policy,
        array $roleOptions,
        array $drawProofs,
        BasePath $basePath,
        string $csrfToken,
        bool $updated,
    ): string {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $starts = $giveaway?->startsAt ?? $now->modify('+1 hour');
        $ends = $giveaway?->endsAt ?? $now->modify('+1 day');
        $action = self::e($basePath->prepend('/giveaways/manage'));
        $notice = $updated ? '<div class="notice success">Çekiliş işlemi kaydedildi.</div>' : '';

        $body = '<section class="card"><h1>Çekiliş Yönetimi</h1>'
            . '<p class="muted">Zamanlama, yaşam döngüsü ve katılım uygunluk kuralları bu ekrandan yönetilir.</p>'
            . $notice
            . '<form method="post" action="' . $action . '" class="presence-settings">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="sync_due">'
            . '<button type="submit">Zamanı gelen durumları senkronize et</button></form>';

        if ($giveaways !== []) {
            $body .= '<section class="section"><h2>Yönetilebilir çekilişler</h2><div class="search-results">';
            foreach ($giveaways as $item) {
                $body .= self::card($item, $basePath, true);
            }
            $body .= '</div></section>';
        }

        $editable = $giveaway === null
            || in_array($giveaway->state, [GiveawayState::Draft, GiveawayState::Scheduled], true);

        if ($editable) {
            $body .= '<section class="section"><h2>'
                . ($giveaway === null ? 'Yeni çekiliş' : 'Çekilişi düzenle')
                . '</h2><form method="post" action="' . $action . '" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="save">'
                . '<input type="hidden" name="giveaway_id" value="' . self::e($giveaway?->giveawayId->value() ?? '') . '">'
                . '<label><span>Slug</span><input name="slug" maxlength="160" required value="'
                . self::e($giveaway?->slug ?? '') . '"></label>'
                . '<label class="search-wide"><span>Başlık</span><input name="title" maxlength="180" required value="'
                . self::e($giveaway?->title ?? '') . '"></label>'
                . '<label class="search-wide"><span>Açıklama</span><textarea name="description" maxlength="100000" rows="10" required>'
                . self::e($giveaway?->description ?? '') . '</textarea></label>'
                . '<label class="search-wide"><span>Ödül adı</span><input name="prize_title" maxlength="180" required value="'
                . self::e($giveaway?->prize->title ?? '') . '"></label>'
                . '<label class="search-wide"><span>Ödül açıklaması</span><textarea name="prize_description" maxlength="2000" rows="4">'
                . self::e($giveaway?->prize->description ?? '') . '</textarea></label>'
                . '<label><span>Ödül adedi</span><input type="number" name="prize_quantity" min="1" max="1000000" value="'
                . ($giveaway?->prize->quantity ?? 1) . '" required></label>'
                . '<label class="search-wide"><span>Katılım koşulları</span><textarea name="participation_terms" maxlength="20000" rows="8" required>'
                . self::e($giveaway?->participationTerms ?? '') . '</textarea></label>'
                . '<label><span>Başlangıç (UTC)</span><input type="datetime-local" name="starts_at" value="'
                . self::e($starts->format('Y-m-d\TH:i')) . '" required></label>'
                . '<label><span>Bitiş (UTC)</span><input type="datetime-local" name="ends_at" value="'
                . self::e($ends->format('Y-m-d\TH:i')) . '" required></label>'
                . '<label><span>Kişi başı hak</span><input type="number" name="entries_per_user" min="1" max="1000" value="'
                . ($giveaway?->entriesPerUser ?? 1) . '" required></label>'
                . '<label><span>Maksimum katılımcı</span><input type="number" name="max_participants" min="1" max="100000000" value="'
                . self::e($giveaway?->maxParticipants === null ? '' : (string) $giveaway->maxParticipants)
                . '" placeholder="Sınırsız"></label>'
                . '<div class="search-actions"><button type="submit">Taslak olarak kaydet</button></div></form></section>';
        }

        if ($giveaway !== null && $policy !== null && $editable) {
            $selectedRoles = array_fill_keys(
                array_map(static fn ($id): string => $id->value(), $policy->allowedRoleIds),
                true,
            );
            $roleFields = '';
            foreach ($roleOptions as $option) {
                $checked = isset($selectedRoles[$option->roleId->value()]) ? ' checked' : '';
                $roleFields .= '<label><input type="checkbox" name="role_ids[]" value="'
                    . self::e($option->roleId->value()) . '"' . $checked . '> ' . self::e($option->name) . '</label>';
            }
            if ($roleFields === '') {
                $roleFields = '<p class="muted">Tanımlı rol bulunmuyor; rol koşulu uygulanmayacak.</p>';
            }
            $body .= '<section class="section"><h2>Katılım uygunluğu</h2>'
                . '<form method="post" action="' . $action . '" class="search-form">'
                . self::csrf($csrfToken)
                . '<input type="hidden" name="action" value="policy_save">'
                . '<input type="hidden" name="giveaway_id" value="' . self::e($giveaway->giveawayId->value()) . '">'
                . '<label><span>Minimum hesap yaşı (gün)</span><input type="number" name="min_account_age_days" min="0" max="36500" value="'
                . $policy->minAccountAgeDays . '"></label>'
                . '<label><span>Minimum görünür mesaj</span><input type="number" name="min_post_count" min="0" max="100000000" value="'
                . $policy->minPostCount . '"></label>'
                . '<label><input type="checkbox" name="require_verified_account" value="1"'
                . ($policy->requireVerifiedAccount ? ' checked' : '') . '> Doğrulanmış hesap gerekli</label>'
                . '<fieldset class="search-wide"><legend>İzinli roller (boş = tüm roller)</legend>' . $roleFields . '</fieldset>'
                . '<label><span>Referral koşulu</span><select name="referral_requirement">'
                . self::option('none', 'Yok', $policy->referralRequirement->value)
                . self::option('referred_qualified', 'Nitelikli referral ile gelmiş kullanıcı', $policy->referralRequirement->value)
                . self::option('qualified_referrer', 'Nitelikli referral kazandırmış kullanıcı', $policy->referralRequirement->value)
                . '</select></label>'
                . '<label><span>Minimum nitelikli referral</span><input type="number" name="min_qualified_referrals" min="1" max="1000000" value="'
                . max(1, $policy->minQualifiedReferrals) . '"></label>'
                . '<label><span>Aynı ağdan maksimum hesap (0 = kapalı)</span><input type="number" name="duplicate_network_limit" min="0" max="1000" value="'
                . $policy->duplicateNetworkLimit . '"></label>'
                . '<label><span>Aynı cihaz sinyalinden maksimum hesap (0 = kapalı)</span><input type="number" name="duplicate_device_limit" min="0" max="1000" value="'
                . $policy->duplicateDeviceLimit . '"></label>'
                . '<div class="search-actions"><button type="submit">Uygunluk kurallarını kaydet</button></div>'
                . '</form></section>';
        }

        if ($giveaway !== null && $giveaway->state === GiveawayState::Closed) {
            $body .= '<section class="section"><h2>Kazanan seçimi</h2>';
            if ($drawProofs === []) {
                $body .= '<p class="muted">Henüz kazanan seçilmedi. İlk seçim yalnız bir kez oluşturulabilir ve kalıcı audit kaydı bırakır.</p>'
                    . self::actionForm($action, $csrfToken, 'draw', $giveaway, 'Kriptografik kazanan seçimi yap');
            } else {
                $latestProof = $drawProofs[count($drawProofs) - 1];
                $body .= '<p>Güncel kazanan kullanıcı kimliği: <code>'
                    . self::e($latestProof->draw->winnerUserId->value()) . '</code></p>'
                    . '<p class="muted">Draw #' . $latestProof->draw->sequence
                    . ' · Kanıt: ' . ($latestProof->verified ? 'Doğrulandı' : 'Doğrulanamadı') . '</p>'
                    . '<p><a href="' . self::e($basePath->prepend(
                        '/giveaways/' . rawurlencode($giveaway->giveawayId->value()) . '/proof',
                    )) . '">Tüm seçim/audit zincirini aç</a></p>'
                    . '<form method="post" action="' . $action . '" class="search-form">'
                    . self::csrf($csrfToken)
                    . '<input type="hidden" name="action" value="redraw">'
                    . '<input type="hidden" name="giveaway_id" value="' . self::e($giveaway->giveawayId->value()) . '">'
                    . '<label class="search-wide"><span>Yeniden çekim gerekçesi</span>'
                    . '<textarea name="redraw_reason" minlength="10" maxlength="500" rows="4" required></textarea></label>'
                    . '<div class="search-actions"><button type="submit">Gerekçeli yeniden çekim yap</button></div>'
                    . '</form>';
            }
            $body .= '</section>';
        }

        if ($giveaway !== null) {
            $body .= '<section class="section"><h2>Yaşam döngüsü</h2><p class="muted">Mevcut durum: '
                . self::e(self::stateLabel($giveaway->state)) . '</p>';
            if (in_array($giveaway->state, [GiveawayState::Draft, GiveawayState::Scheduled], true)) {
                $body .= self::actionForm($action, $csrfToken, 'publish', $giveaway, 'Yayımla / zamanla');
            }
            if (!$giveaway->state->isTerminal()) {
                $body .= self::actionForm($action, $csrfToken, 'cancel', $giveaway, 'İptal et');
            }
            $body .= '</section>';
        }

        $body .= '</section>';
        return ProfileHtml::page('Çekiliş Yönetimi', $body, $basePath, authenticated:true);
    }

    /**
     * @param list<GiveawayDrawProof> $proofs
     * @param array<string,string> $winnerNames
     */
    public static function proof(
        Giveaway $giveaway,
        array $proofs,
        array $winnerNames,
        BasePath $basePath,
    ): string {
        $body = '<section class="card"><h1>Kazanan Seçim Kanıtı</h1>'
            . '<p><a href="' . self::e($basePath->prepend(
                '/giveaways/' . rawurlencode($giveaway->giveawayId->value()),
            )) . '">← Çekilişe dön</a></p>'
            . '<p class="muted">Algoritma: <code>' . self::e(\Forwext\Core\Giveaway\GiveawayDraw::ALGORITHM)
            . '</code>. Her kayıt immutable population snapshot, açıklanan CSPRNG seed ve rejection-sampling bileti ile tekrar doğrulanır.</p>';

        if ($proofs === []) {
            $body .= '<div class="empty">Bu çekiliş için henüz kazanan seçimi yapılmadı.</div>';
        } else {
            foreach ($proofs as $proof) {
                $draw = $proof->draw;
                $winner = $winnerNames[$draw->winnerUserId->value()] ?? 'Silinmiş veya erişilemeyen kullanıcı';
                $body .= '<article class="section"><h2>Draw #' . $draw->sequence
                    . ($proof->current ? ' · Güncel sonuç' : ' · Önceki sonuç') . '</h2>'
                    . '<p><strong>' . ($proof->verified ? 'Kanıt doğrulandı' : 'Kanıt doğrulanamadı') . '</strong></p>'
                    . '<dl>'
                    . '<dt>Tür</dt><dd>' . self::e($draw->kind->value) . '</dd>'
                    . '<dt>Kazanan</dt><dd>' . self::e($winner) . ' · <code>'
                    . self::e($draw->winnerUserId->value()) . '</code></dd>'
                    . '<dt>Katılımcı snapshot</dt><dd>' . $draw->participantCount . ' kullanıcı · '
                    . $draw->totalWeight . ' toplam hak</dd>'
                    . '<dt>Seçilen bilet</dt><dd>' . $draw->selectedTicket . '</dd>'
                    . '<dt>Population SHA-256</dt><dd><code>' . self::e($draw->populationHash) . '</code></dd>'
                    . '<dt>Seed</dt><dd><code>' . self::e($draw->seedHex) . '</code></dd>'
                    . '<dt>Proof SHA-256</dt><dd><code>' . self::e($draw->proofHash) . '</code></dd>'
                    . '<dt>Zaman</dt><dd>' . self::e(self::date($draw->createdAt)) . '</dd>'
                    . '</dl>';
                if ($draw->redrawReason !== null) {
                    $body .= '<p><strong>Yeniden çekim gerekçesi:</strong> ' . self::e($draw->redrawReason) . '</p>';
                }
                $body .= '</article>';
            }
        }
        $body .= '</section>';

        return ProfileHtml::page('Kazanan Seçim Kanıtı', $body, $basePath, authenticated:true);
    }

    private static function actionForm(
        string $action,
        string $csrfToken,
        string $operation,
        Giveaway $giveaway,
        string $label,
    ): string {
        return '<form method="post" action="' . $action . '" class="presence-settings">'
            . self::csrf($csrfToken)
            . '<input type="hidden" name="action" value="' . self::e($operation) . '">'
            . '<input type="hidden" name="giveaway_id" value="' . self::e($giveaway->giveawayId->value()) . '">'
            . '<button type="submit">' . self::e($label) . '</button></form>';
    }

    private static function card(Giveaway $giveaway, BasePath $basePath, bool $manage = false): string
    {
        $path = $manage
            ? '/giveaways/manage?giveaway=' . rawurlencode($giveaway->giveawayId->value())
            : '/giveaways/' . rawurlencode($giveaway->giveawayId->value());
        $href = self::e($basePath->prepend($path));

        if ($manage) {
            return '<article class="search-hit"><div class="search-hit-type">'
                . self::e(self::stateLabel($giveaway->state)) . '</div><h2><a href="' . $href . '">'
                . self::e($giveaway->title) . '</a></h2><p>' . self::e($giveaway->prize->title)
                . ' × ' . $giveaway->prize->quantity . '</p><p class="muted">'
                . self::e(self::date($giveaway->startsAt)) . ' → ' . self::e(self::date($giveaway->endsAt))
                . '</p></article>';
        }

        return '<article class="giveaway-card"><div class="giveaway-card-top"><span>'
            . self::e(self::stateLabel($giveaway->state)) . '</span></div><div class="giveaway-card-body">'
            . '<h2><a href="' . $href . '">' . self::e($giveaway->title) . '</a></h2>'
            . '<p class="giveaway-card-prize"><strong>' . self::e($giveaway->prize->title) . '</strong>'
            . '<span>× ' . $giveaway->prize->quantity . '</span></p>'
            . '<p class="giveaway-card-date">' . self::e(self::date($giveaway->startsAt))
            . '<span aria-hidden="true"> → </span>' . self::e(self::date($giveaway->endsAt)) . '</p>'
            . '</div></article>';
    }

    private static function option(string $value, string $label, string $selected): string
    {
        return '<option value="' . self::e($value) . '"' . ($value === $selected ? ' selected' : '') . '>'
            . self::e($label) . '</option>';
    }

    private static function referralLabel(GiveawayEligibilityPolicy $policy): string
    {
        return match ($policy->referralRequirement) {
            GiveawayReferralRequirement::None => 'Yok',
            GiveawayReferralRequirement::ReferredQualified => 'Nitelikli referral ile kayıt olmuş olmalı',
            GiveawayReferralRequirement::QualifiedReferrer => 'En az ' . $policy->minQualifiedReferrals . ' nitelikli referral',
        };
    }

    private static function eligibilityReason(string $reason): string
    {
        return match ($reason) {
            'self_entry' => 'Çekiliş sahibi kendi çekilişine katılamaz.',
            'not_open' => 'Çekiliş şu anda katılıma açık değil.',
            'account_restricted' => 'Hesap durumu katılıma uygun değil.',
            'account_not_verified' => 'Doğrulanmış hesap gerekli.',
            'account_age' => 'Minimum hesap yaşı koşulu karşılanmıyor.',
            'post_count' => 'Minimum görünür mesaj koşulu karşılanmıyor.',
            'role' => 'Gerekli rol koşulu karşılanmıyor.',
            'referral' => 'Referral koşulu karşılanmıyor.',
            'duplicate_network' => 'Aynı ağ için katılım sınırına ulaşıldı.',
            'duplicate_device' => 'Aynı cihaz sinyali için katılım sınırına ulaşıldı.',
            'capacity_reached' => 'Maksimum katılımcı sayısına ulaşıldı.',
            default => 'Katılım uygunluk koşulları karşılanmıyor.',
        };
    }

    private static function stateLabel(GiveawayState $state): string
    {
        return match ($state) {
            GiveawayState::Draft => 'Taslak',
            GiveawayState::Scheduled => 'Planlandı',
            GiveawayState::Open => 'Aktif',
            GiveawayState::Closed => 'Kapandı',
            GiveawayState::Cancelled => 'İptal edildi',
        };
    }

    private static function date(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i') . ' UTC';
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
