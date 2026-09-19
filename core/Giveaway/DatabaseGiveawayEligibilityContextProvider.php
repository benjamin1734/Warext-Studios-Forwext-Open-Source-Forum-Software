<?php

declare(strict_types=1);

namespace Forwext\Core\Giveaway;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use RuntimeException;

final readonly class DatabaseGiveawayEligibilityContextProvider implements GiveawayEligibilityContextProvider
{
    public function __construct(
        private QueryExecutor $database,
        private UserRepository $users,
        private UserAccessAssignmentProvider $assignments,
    ) {
    }

    public function context(EntityId $userId): GiveawayEligibilityContext
    {
        $user = $this->users->find($userId)
            ?? throw new RuntimeException('Giveaway eligibility user was not found.');
        $assignment = $this->assignments->find($userId);

        $posts = (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_posts WHERE author_user_id=:user_id "
            . "AND deleted=0 AND moderation_state='visible'",
            ['user_id'=>$userId->value()],
        ));
        $referredQualified = (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_referral_attributions "
            . "WHERE referred_user_id=:user_id AND state='qualified'",
            ['user_id'=>$userId->value()],
        )) > 0;
        $qualifiedReferrals = (int) $this->database->fetchValue(new CompiledQuery(
            "SELECT COUNT(*) FROM forwext_referral_attributions "
            . "WHERE referrer_user_id=:user_id AND state='qualified'",
            ['user_id'=>$userId->value()],
        ));

        return new GiveawayEligibilityContext(
            $userId,
            $user->status(),
            $user->createdAt(),
            $posts,
            $assignment?->roleIds() ?? [],
            $referredQualified,
            $qualifiedReferrals,
        );
    }
}
