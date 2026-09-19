<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabasePromotionMetricProvider implements PromotionMetricProvider
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function metric(EntityId $userId,PromotionRuleType $ruleType,DateTimeImmutable $now):int
    {
        UserId::assert($userId);
        $nowValue=$now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        return match($ruleType){
            PromotionRuleType::AccountAgeDays=>max(0,(int)$this->database->fetchValue(new CompiledQuery(
                'SELECT COALESCE(TIMESTAMPDIFF(DAY,created_at_utc,:now),0) FROM forwext_users WHERE user_id=:user_id',
                ['now'=>$nowValue,'user_id'=>$userId->value()]
            ))),
            PromotionRuleType::VisiblePostCount=>(int)$this->database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_posts WHERE author_user_id=:user_id AND deleted=0 AND moderation_state='visible'",
                ['user_id'=>$userId->value()]
            )),
            PromotionRuleType::QualifiedReferralCount=>(int)$this->database->fetchValue(new CompiledQuery(
                "SELECT COUNT(*) FROM forwext_referral_attributions WHERE referrer_user_id=:user_id AND state='qualified'",
                ['user_id'=>$userId->value()]
            )),
            PromotionRuleType::GiveawayWinCount=>(int)$this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM forwext_giveaway_draws d INNER JOIN '
                . '(SELECT giveaway_id,MAX(sequence) AS max_sequence FROM forwext_giveaway_draws GROUP BY giveaway_id) latest '
                . 'ON latest.giveaway_id=d.giveaway_id AND latest.max_sequence=d.sequence '
                . 'WHERE d.winner_user_id=:user_id',
                ['user_id'=>$userId->value()]
            )),
            PromotionRuleType::ActiveTrophyCount=>(int)$this->database->fetchValue(new CompiledQuery(
                'SELECT COUNT(*) FROM forwext_user_trophies g INNER JOIN forwext_trophies t ON t.trophy_id=g.trophy_id '
                . 'WHERE g.user_id=:user_id AND g.revoked_at_utc IS NULL AND t.active=1',
                ['user_id'=>$userId->value()]
            )),
        };
    }
}
