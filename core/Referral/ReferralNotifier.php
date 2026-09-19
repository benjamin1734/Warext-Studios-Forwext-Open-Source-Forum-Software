<?php

declare(strict_types=1);

namespace Forwext\Core\Referral;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class ReferralNotifier
{
    public const QUALIFIED = 'referral.qualified';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::QUALIFIED,
            'referral',
            'Referansınız nitelikli oldu',
            '{{campaign}} kampanyasında bir referansınız nitelikli oldu. Ödül: {{units}} {{reward}}.',
        ));
    }

    public function qualified(ReferralAttribution $attribution, ReferralCampaign $campaign): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $attribution->referrerUserId,
            self::QUALIFIED,
            [
                'campaign'=>$campaign->name,
                'units'=>(string) $campaign->rewardUnits,
                'reward'=>$campaign->rewardKey,
            ],
            null,
            'referral-qualified:' . $attribution->attributionId->value(),
            '/account/referrals',
            [
                'attribution_id'=>$attribution->attributionId->value(),
                'campaign_id'=>$campaign->campaignId->value(),
                'reward_units'=>$campaign->rewardUnits,
            ],
        ));
    }
}
