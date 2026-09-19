<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabaseReferralRepository implements ReferralRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function campaigns(bool $activeOnly = false): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_referral_campaigns'
            . ($activeOnly ? ' WHERE active=1' : '')
            . ' ORDER BY active DESC,starts_at_utc DESC,campaign_id DESC',
        ));
        return array_map($this->hydrateCampaign(...), $rows);
    }

    public function campaign(EntityId $campaignId): ?ReferralCampaign
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_referral_campaigns WHERE campaign_id=:campaign_id LIMIT 1',
            ['campaign_id'=>$campaignId->value()],
        ));
        return $row === null ? null : $this->hydrateCampaign($row);
    }

    public function saveCampaign(ReferralCampaign $campaign, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_referral_campaigns '
            . '(campaign_id,campaign_key,name,active,starts_at_utc,ends_at_utc,qualification_delay_seconds,'
            . 'attribution_window_seconds,duplicate_network_limit,duplicate_device_limit,max_qualified_per_referrer,'
            . 'reward_key,reward_units,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:key,:name,:active,:starts,:ends,:delay,:window,:network_limit,:device_limit,:max_qualified,'
            . ':reward_key,:reward_units,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE campaign_key=VALUES(campaign_key),name=VALUES(name),active=VALUES(active),'
            . 'starts_at_utc=VALUES(starts_at_utc),ends_at_utc=VALUES(ends_at_utc),'
            . 'qualification_delay_seconds=VALUES(qualification_delay_seconds),'
            . 'attribution_window_seconds=VALUES(attribution_window_seconds),'
            . 'duplicate_network_limit=VALUES(duplicate_network_limit),'
            . 'duplicate_device_limit=VALUES(duplicate_device_limit),'
            . 'max_qualified_per_referrer=VALUES(max_qualified_per_referrer),reward_key=VALUES(reward_key),'
            . 'reward_units=VALUES(reward_units),updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$campaign->campaignId->value(),
                'key'=>$campaign->key,
                'name'=>$campaign->name,
                'active'=>$campaign->active ? 1 : 0,
                'starts'=>self::format($campaign->startsAt),
                'ends'=>$campaign->endsAt === null ? null : self::format($campaign->endsAt),
                'delay'=>$campaign->qualificationDelaySeconds,
                'window'=>$campaign->attributionWindowSeconds,
                'network_limit'=>$campaign->duplicateNetworkLimit,
                'device_limit'=>$campaign->duplicateDeviceLimit,
                'max_qualified'=>$campaign->maxQualifiedPerReferrer,
                'reward_key'=>$campaign->rewardKey,
                'reward_units'=>$campaign->rewardUnits,
                'created'=>self::format($at),
                'updated'=>self::format($at),
            ],
        ));
    }

    public function linkForOwner(EntityId $campaignId, EntityId $ownerUserId): ?ReferralLink
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_referral_links '
            . 'WHERE campaign_id=:campaign_id AND owner_user_id=:owner_user_id LIMIT 1',
            ['campaign_id'=>$campaignId->value(),'owner_user_id'=>$ownerUserId->value()],
        ));
        return $row === null ? null : $this->hydrateLink($row);
    }

    public function linkByCode(string $code): ?ReferralLink
    {
        if (preg_match('/^[A-Za-z0-9_-]{24,64}$/D', $code) !== 1) {
            return null;
        }
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_referral_links WHERE code=:code LIMIT 1',
            ['code'=>$code],
        ));
        return $row === null ? null : $this->hydrateLink($row);
    }

    public function saveLink(ReferralLink $link, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_referral_links '
            . '(link_id,campaign_id,owner_user_id,code,expires_at_utc,disabled,created_at_utc,updated_at_utc) '
            . 'VALUES (:id,:campaign_id,:owner,:code,:expires,:disabled,:created,:updated) '
            . 'ON DUPLICATE KEY UPDATE expires_at_utc=VALUES(expires_at_utc),disabled=VALUES(disabled),'
            . 'updated_at_utc=VALUES(updated_at_utc)',
            [
                'id'=>$link->linkId->value(),
                'campaign_id'=>$link->campaignId->value(),
                'owner'=>$link->ownerUserId->value(),
                'code'=>$link->code,
                'expires'=>$link->expiresAt === null ? null : self::format($link->expiresAt),
                'disabled'=>$link->disabled ? 1 : 0,
                'created'=>self::format($link->createdAt),
                'updated'=>self::format($at),
            ],
        ));
    }

    public function recordClick(ReferralLink $link, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_referral_clicks '
            . '(click_id,link_id,campaign_id,referrer_user_id,clicked_at_utc) '
            . 'VALUES (:id,:link_id,:campaign_id,:referrer,:clicked)',
            [
                'id'=>bin2hex(random_bytes(16)),
                'link_id'=>$link->linkId->value(),
                'campaign_id'=>$link->campaignId->value(),
                'referrer'=>$link->ownerUserId->value(),
                'clicked'=>self::format($at),
            ],
        ));
    }

    public function attributionByReferred(EntityId $referredUserId): ?ReferralAttribution
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_referral_attributions WHERE referred_user_id=:user_id LIMIT 1',
            ['user_id'=>$referredUserId->value()],
        ));
        return $row === null ? null : $this->hydrateAttribution($row);
    }

    public function attribution(EntityId $attributionId): ?ReferralAttribution
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_referral_attributions WHERE attribution_id=:id LIMIT 1',
            ['id'=>$attributionId->value()],
        ));
        return $row === null ? null : $this->hydrateAttribution($row);
    }

    public function saveAttribution(ReferralAttribution $attribution): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_referral_attributions '
            . '(attribution_id,campaign_id,link_id,referrer_user_id,referred_user_id,state,risk_code,ip_fingerprint,'
            . 'device_fingerprint,attributed_at_utc,eligible_at_utc,qualified_at_utc,reviewed_at_utc) '
            . 'VALUES (:id,:campaign_id,:link_id,:referrer,:referred,:state,:risk,:ip,:device,:attributed,:eligible,:qualified,:reviewed) '
            . 'ON DUPLICATE KEY UPDATE state=VALUES(state),risk_code=VALUES(risk_code),'
            . 'qualified_at_utc=VALUES(qualified_at_utc),reviewed_at_utc=VALUES(reviewed_at_utc)',
            [
                'id'=>$attribution->attributionId->value(),
                'campaign_id'=>$attribution->campaignId->value(),
                'link_id'=>$attribution->linkId->value(),
                'referrer'=>$attribution->referrerUserId->value(),
                'referred'=>$attribution->referredUserId->value(),
                'state'=>$attribution->state->value,
                'risk'=>$attribution->riskCode,
                'ip'=>$attribution->ipFingerprint,
                'device'=>$attribution->deviceFingerprint,
                'attributed'=>self::format($attribution->attributedAt),
                'eligible'=>self::format($attribution->eligibleAt),
                'qualified'=>$attribution->qualifiedAt === null ? null : self::format($attribution->qualifiedAt),
                'reviewed'=>$attribution->reviewedAt === null ? null : self::format($attribution->reviewedAt),
            ],
        ));
    }

    public function fingerprintCount(
        EntityId $campaignId,
        EntityId $referrerUserId,
        string $kind,
        string $fingerprint,
    ): int {
        $column = match ($kind) {
            'ip' => 'ip_fingerprint',
            'device' => 'device_fingerprint',
            default => throw new InvalidArgumentException('Referral fingerprint kind is invalid.'),
        };
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Referral fingerprint is invalid.');
        }
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_referral_attributions '
            . 'WHERE campaign_id=:campaign_id AND referrer_user_id=:referrer AND state<>\'rejected\' '
            . 'AND ' . $column . '=:fingerprint',
            [
                'campaign_id'=>$campaignId->value(),
                'referrer'=>$referrerUserId->value(),
                'fingerprint'=>$fingerprint,
            ],
        ));
    }

    public function dueAttributions(DateTimeImmutable $at, int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Referral qualification limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT * FROM forwext_referral_attributions WHERE state='attributed' "
            . 'AND eligible_at_utc<=:eligible ORDER BY eligible_at_utc,attribution_id LIMIT ' . $limit,
            ['eligible'=>self::format($at)],
        ));
        return array_map($this->hydrateAttribution(...), $rows);
    }

    public function reviewQueue(int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Referral review limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            "SELECT * FROM forwext_referral_attributions WHERE state='review' "
            . 'ORDER BY attributed_at_utc,attribution_id LIMIT ' . $limit,
        ));
        return array_map($this->hydrateAttribution(...), $rows);
    }

    public function qualifiedCount(EntityId $campaignId, EntityId $referrerUserId): int
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_referral_attributions "
            . "WHERE campaign_id=:campaign_id AND referrer_user_id=:referrer AND state='qualified'",
            ['campaign_id'=>$campaignId->value(),'referrer'=>$referrerUserId->value()],
        ));
    }

    public function rewardForAttribution(EntityId $attributionId): ?ReferralReward
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_referral_rewards WHERE attribution_id=:id LIMIT 1',
            ['id'=>$attributionId->value()],
        ));
        return $row === null ? null : $this->hydrateReward($row);
    }

    public function saveReward(ReferralReward $reward): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_referral_rewards '
            . '(reward_id,attribution_id,campaign_id,recipient_user_id,reward_key,units,state,granted_at_utc,revoked_at_utc) '
            . 'VALUES (:id,:attribution_id,:campaign_id,:recipient,:reward_key,:units,:state,:granted,:revoked) '
            . 'ON DUPLICATE KEY UPDATE state=VALUES(state),revoked_at_utc=VALUES(revoked_at_utc)',
            [
                'id'=>$reward->rewardId->value(),
                'attribution_id'=>$reward->attributionId->value(),
                'campaign_id'=>$reward->campaignId->value(),
                'recipient'=>$reward->recipientUserId->value(),
                'reward_key'=>$reward->rewardKey,
                'units'=>$reward->units,
                'state'=>$reward->state->value,
                'granted'=>self::format($reward->grantedAt),
                'revoked'=>$reward->revokedAt === null ? null : self::format($reward->revokedAt),
            ],
        ));
    }

    public function rewardsForUser(EntityId $userId, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('Referral reward limit is invalid.');
        }
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT * FROM forwext_referral_rewards WHERE recipient_user_id=:user_id '
            . 'ORDER BY granted_at_utc DESC,reward_id DESC LIMIT ' . $limit,
            ['user_id'=>$userId->value()],
        ));
        return array_map($this->hydrateReward(...), $rows);
    }

    public function analytics(?EntityId $ownerUserId = null, ?EntityId $campaignId = null): ReferralAnalytics
    {
        $conditions = [];
        $parameters = [];
        if ($ownerUserId !== null) {
            $conditions[] = 'referrer_user_id=:owner';
            $parameters['owner'] = $ownerUserId->value();
        }
        if ($campaignId !== null) {
            $conditions[] = 'campaign_id=:campaign';
            $parameters['campaign'] = $campaignId->value();
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        $clicks = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_referral_clicks' . $where,
            $parameters,
        ));

        $states = ['attributed'=>0,'review'=>0,'qualified'=>0,'rejected'=>0];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT state,COUNT(*) AS total FROM forwext_referral_attributions'
            . $where . ' GROUP BY state',
            $parameters,
        )) as $row) {
            $state = (string) ($row['state'] ?? '');
            if (array_key_exists($state, $states)) {
                $states[$state] = (int) $row['total'];
            }
        }

        $rewardConditions = [];
        $rewardParameters = [];
        if ($ownerUserId !== null) {
            $rewardConditions[] = 'recipient_user_id=:owner';
            $rewardParameters['owner'] = $ownerUserId->value();
        }
        if ($campaignId !== null) {
            $rewardConditions[] = 'campaign_id=:campaign';
            $rewardParameters['campaign'] = $campaignId->value();
        }
        $rewardWhere = $rewardConditions === [] ? '' : ' WHERE ' . implode(' AND ', $rewardConditions);
        $rewardWhere .= $rewardWhere === '' ? " WHERE state='granted'" : " AND state='granted'";
        $rewardUnits = (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COALESCE(SUM(units),0) FROM forwext_referral_rewards' . $rewardWhere,
            $rewardParameters,
        ));

        return new ReferralAnalytics(
            $clicks,
            array_sum($states),
            $states['review'],
            $states['qualified'],
            $states['rejected'],
            $rewardUnits,
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateCampaign(array $row): ReferralCampaign
    {
        return new ReferralCampaign(
            EntityId::fromString((string) $row['campaign_id']),
            (string) $row['campaign_key'],
            (string) $row['name'],
            (bool) $row['active'],
            self::parse((string) $row['starts_at_utc']),
            isset($row['ends_at_utc']) && is_string($row['ends_at_utc']) ? self::parse($row['ends_at_utc']) : null,
            (int) $row['qualification_delay_seconds'],
            (int) $row['attribution_window_seconds'],
            (int) $row['duplicate_network_limit'],
            (int) $row['duplicate_device_limit'],
            isset($row['max_qualified_per_referrer']) ? (int) $row['max_qualified_per_referrer'] : null,
            (string) $row['reward_key'],
            (int) $row['reward_units'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateLink(array $row): ReferralLink
    {
        return new ReferralLink(
            EntityId::fromString((string) $row['link_id']),
            EntityId::fromString((string) $row['campaign_id']),
            EntityId::fromString((string) $row['owner_user_id']),
            (string) $row['code'],
            isset($row['expires_at_utc']) && is_string($row['expires_at_utc']) ? self::parse($row['expires_at_utc']) : null,
            (bool) $row['disabled'],
            self::parse((string) $row['created_at_utc']),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateAttribution(array $row): ReferralAttribution
    {
        try {
            $state = ReferralState::from((string) $row['state']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored referral state is invalid.', previous:$exception);
        }
        return new ReferralAttribution(
            EntityId::fromString((string) $row['attribution_id']),
            EntityId::fromString((string) $row['campaign_id']),
            EntityId::fromString((string) $row['link_id']),
            EntityId::fromString((string) $row['referrer_user_id']),
            EntityId::fromString((string) $row['referred_user_id']),
            $state,
            isset($row['risk_code']) && is_string($row['risk_code']) ? $row['risk_code'] : null,
            (string) $row['ip_fingerprint'],
            isset($row['device_fingerprint']) && is_string($row['device_fingerprint']) ? $row['device_fingerprint'] : null,
            self::parse((string) $row['attributed_at_utc']),
            self::parse((string) $row['eligible_at_utc']),
            isset($row['qualified_at_utc']) && is_string($row['qualified_at_utc']) ? self::parse($row['qualified_at_utc']) : null,
            isset($row['reviewed_at_utc']) && is_string($row['reviewed_at_utc']) ? self::parse($row['reviewed_at_utc']) : null,
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateReward(array $row): ReferralReward
    {
        try {
            $state = ReferralRewardState::from((string) $row['state']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored referral reward state is invalid.', previous:$exception);
        }
        return new ReferralReward(
            EntityId::fromString((string) $row['reward_id']),
            EntityId::fromString((string) $row['attribution_id']),
            EntityId::fromString((string) $row['campaign_id']),
            EntityId::fromString((string) $row['recipient_user_id']),
            (string) $row['reward_key'],
            (int) $row['units'],
            $state,
            self::parse((string) $row['granted_at_utc']),
            isset($row['revoked_at_utc']) && is_string($row['revoked_at_utc']) ? self::parse($row['revoked_at_utc']) : null,
        );
    }

    private static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }
        throw new RuntimeException('Stored referral timestamp is invalid.');
    }
}
