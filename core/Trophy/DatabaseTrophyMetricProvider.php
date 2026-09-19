<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use LogicException;

final readonly class DatabaseTrophyMetricProvider implements TrophyMetricProvider
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function metric(EntityId $userId, TrophyRuleType $ruleType, DateTimeImmutable $now): int
    {
        UserId::assert($userId);
        $nowValue = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return match ($ruleType) {
            TrophyRuleType::Manual => throw new LogicException('Manual trophies do not expose a rule metric.'),
            TrophyRuleType::AccountAgeDays => max(0, (int) $this->database->fetchValue(new CompiledQuery(
                'SELECT COALESCE(TIMESTAMPDIFF(DAY,created_at_utc,:now),0) FROM forwext_users WHERE user_id=:user_id',
                ['now'=>$nowValue,'user_id'=>$userId->value()],
            ))),
            TrophyRuleType::VisiblePostCount => (int) $this->database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_posts WHERE author_user_id=:user_id AND deleted=0 AND moderation_state='visible'",
                ['user_id'=>$userId->value()],
            )),
            TrophyRuleType::QualifiedReferralCount => (int) $this->database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_referral_attributions WHERE referrer_user_id=:user_id AND state='qualified'",
                ['user_id'=>$userId->value()],
            )),
            TrophyRuleType::GiveawayWinCount => (int) $this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM forwext_giveaway_draws d '
                . 'INNER JOIN (SELECT giveaway_id,MAX(sequence) AS max_sequence FROM forwext_giveaway_draws GROUP BY giveaway_id) latest '
                . 'ON latest.giveaway_id=d.giveaway_id AND latest.max_sequence=d.sequence '
                . 'WHERE d.winner_user_id=:user_id',
                ['user_id'=>$userId->value()],
            )),
        };
    }
}
