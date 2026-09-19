<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use InvalidArgumentException;
use RuntimeException;
use ValueError;

final readonly class DatabaseGiveawayParticipationRepository implements GiveawayParticipationRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function policy(EntityId $giveawayId): ?GiveawayEligibilityPolicy
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_giveaway_eligibility WHERE giveaway_id=:giveaway_id LIMIT 1',
            ['giveaway_id'=>$giveawayId->value()],
        ));
        if ($row === null) {
            return null;
        }

        $roles = $this->database->fetchAll(new CompiledQuery(
            'SELECT role_id FROM forwext_giveaway_eligible_roles '
            . 'WHERE giveaway_id=:giveaway_id ORDER BY role_id',
            ['giveaway_id'=>$giveawayId->value()],
        ));
        try {
            $referral = GiveawayReferralRequirement::from((string) $row['referral_requirement']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored giveaway referral requirement is invalid.', previous:$exception);
        }

        return new GiveawayEligibilityPolicy(
            $giveawayId,
            (int) $row['min_account_age_days'],
            (int) $row['min_post_count'],
            (bool) $row['require_verified_account'],
            array_map(
                static fn (array $role): EntityId => EntityId::fromString((string) $role['role_id']),
                $roles,
            ),
            $referral,
            (int) $row['min_qualified_referrals'],
            (int) $row['duplicate_network_limit'],
            (int) $row['duplicate_device_limit'],
        );
    }

    public function savePolicy(GiveawayEligibilityPolicy $policy, DateTimeImmutable $at): void
    {
        $this->database->transaction(function () use ($policy, $at): void {
            $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_giveaway_eligibility '
                . '(giveaway_id,min_account_age_days,min_post_count,require_verified_account,referral_requirement,'
                . 'min_qualified_referrals,duplicate_network_limit,duplicate_device_limit,updated_at_utc) '
                . 'VALUES (:giveaway,:age,:posts,:verified,:referral,:min_referrals,:network_limit,:device_limit,:updated) '
                . 'ON DUPLICATE KEY UPDATE min_account_age_days=VALUES(min_account_age_days),'
                . 'min_post_count=VALUES(min_post_count),require_verified_account=VALUES(require_verified_account),'
                . 'referral_requirement=VALUES(referral_requirement),min_qualified_referrals=VALUES(min_qualified_referrals),'
                . 'duplicate_network_limit=VALUES(duplicate_network_limit),'
                . 'duplicate_device_limit=VALUES(duplicate_device_limit),updated_at_utc=VALUES(updated_at_utc)',
                [
                    'giveaway'=>$policy->giveawayId->value(),
                    'age'=>$policy->minAccountAgeDays,
                    'posts'=>$policy->minPostCount,
                    'verified'=>$policy->requireVerifiedAccount ? 1 : 0,
                    'referral'=>$policy->referralRequirement->value,
                    'min_referrals'=>$policy->minQualifiedReferrals,
                    'network_limit'=>$policy->duplicateNetworkLimit,
                    'device_limit'=>$policy->duplicateDeviceLimit,
                    'updated'=>self::format($at),
                ],
            ));
            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_giveaway_eligible_roles WHERE giveaway_id=:giveaway_id',
                ['giveaway_id'=>$policy->giveawayId->value()],
            ));
            foreach ($policy->allowedRoleIds as $roleId) {
                $this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_giveaway_eligible_roles(giveaway_id,role_id) VALUES (:giveaway,:role)',
                    ['giveaway'=>$policy->giveawayId->value(),'role'=>$roleId->value()],
                ));
            }
        });
    }

    public function lockGiveaway(EntityId $giveawayId): bool
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT giveaway_id FROM forwext_giveaways WHERE giveaway_id=:giveaway_id FOR UPDATE',
            ['giveaway_id'=>$giveawayId->value()],
            true,
        ));
        return $row !== null;
    }

    public function entryForUser(EntityId $giveawayId, EntityId $userId): ?GiveawayEntry
    {
        UserId::assert($userId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT * FROM forwext_giveaway_entries '
            . 'WHERE giveaway_id=:giveaway_id AND user_id=:user_id LIMIT 1',
            ['giveaway_id'=>$giveawayId->value(),'user_id'=>$userId->value()],
        ));
        return $row === null ? null : $this->hydrateEntry($row);
    }

    public function participantCount(EntityId $giveawayId): int
    {
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_giveaway_entries WHERE giveaway_id=:giveaway_id',
            ['giveaway_id'=>$giveawayId->value()],
        ));
    }

    public function fingerprintParticipantCount(EntityId $giveawayId, string $kind, string $fingerprint): int
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Giveaway fingerprint is invalid.');
        }
        $column = match ($kind) {
            'network' => 'network_fingerprint',
            'device' => 'device_fingerprint',
            default => throw new InvalidArgumentException('Giveaway fingerprint kind is invalid.'),
        };
        return (int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM forwext_giveaway_entries WHERE giveaway_id=:giveaway_id '
            . 'AND ' . $column . '=:fingerprint',
            ['giveaway_id'=>$giveawayId->value(),'fingerprint'=>$fingerprint],
        ));
    }

    public function saveEntry(GiveawayEntry $entry): void
    {
        $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_giveaway_entries '
            . '(entry_id,giveaway_id,user_id,entry_count,network_fingerprint,device_fingerprint,entered_at_utc) '
            . 'VALUES (:entry,:giveaway,:user,:entry_count,:network,:device,:entered)',
            [
                'entry'=>$entry->entryId->value(),
                'giveaway'=>$entry->giveawayId->value(),
                'user'=>$entry->userId->value(),
                'entry_count'=>$entry->entryCount,
                'network'=>$entry->networkFingerprint,
                'device'=>$entry->deviceFingerprint,
                'entered'=>self::format($entry->enteredAt),
            ],
        ));
    }

    public function availableRoles(): array
    {
        return array_map(
            static fn (array $row): GiveawayEligibilityRoleOption => new GiveawayEligibilityRoleOption(
                EntityId::fromString((string) $row['role_id']),
                (string) $row['name'],
            ),
            $this->database->fetchAll(new CompiledQuery(
                'SELECT role_id,name FROM forwext_roles ORDER BY priority DESC,name,role_id',
            )),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateEntry(array $row): GiveawayEntry
    {
        return new GiveawayEntry(
            EntityId::fromString((string) $row['entry_id']),
            EntityId::fromString((string) $row['giveaway_id']),
            UserId::fromStored((string) $row['user_id']),
            (int) $row['entry_count'],
            (string) $row['network_fingerprint'],
            isset($row['device_fingerprint']) && is_string($row['device_fingerprint'])
                ? $row['device_fingerprint'] : null,
            self::parse((string) $row['entered_at_utc']),
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
        throw new RuntimeException('Stored giveaway entry timestamp is invalid.');
    }
}
