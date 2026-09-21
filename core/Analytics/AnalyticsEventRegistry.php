<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use InvalidArgumentException;

final class AnalyticsEventRegistry
{
    /** @var array<string,AnalyticsEventDefinition> */
    private array $definitions=[];

    /** @param iterable<AnalyticsEventDefinition> $definitions */
    public function __construct(iterable $definitions=[])
    {
        foreach($definitions as $definition)$this->register($definition);
    }

    public static function withCoreDefaults():self
    {
        return new self([
            new AnalyticsEventDefinition('forum.view',AnalyticsEventCategory::Forum,180,true,true,false,true,['route','device']),
            new AnalyticsEventDefinition('forum.search',AnalyticsEventCategory::Forum,180,true,true,false,true,['query_class','result_bucket']),
            new AnalyticsEventDefinition('user.registered',AnalyticsEventCategory::User,365,true,false,true,false,['method','verified']),
            new AnalyticsEventDefinition('user.login',AnalyticsEventCategory::User,180,true,true,false,false,['method','result']),
            new AnalyticsEventDefinition('user.active',AnalyticsEventCategory::User,90,true,true,false,false,['device']),
            new AnalyticsEventDefinition('content.thread.created',AnalyticsEventCategory::Content,365,true,true,true,true,['thread_type','moderation_state']),
            new AnalyticsEventDefinition('content.post.created',AnalyticsEventCategory::Content,365,true,true,true,true,['moderation_state']),
            new AnalyticsEventDefinition('content.reaction',AnalyticsEventCategory::Content,180,true,true,true,true,['reaction']),
            new AnalyticsEventDefinition('support.ticket.created',AnalyticsEventCategory::Support,365,true,false,true,false,['category','priority']),
            new AnalyticsEventDefinition('support.ticket.replied',AnalyticsEventCategory::Support,365,true,false,true,false,['actor_class']),
            new AnalyticsEventDefinition('support.ticket.resolved',AnalyticsEventCategory::Support,365,true,false,true,false,['category','resolution']),
            new AnalyticsEventDefinition('bug.report.created',AnalyticsEventCategory::Bug,365,true,false,true,false,['category','severity']),
            new AnalyticsEventDefinition('bug.report.resolved',AnalyticsEventCategory::Bug,365,true,false,true,false,['category','resolution']),
            new AnalyticsEventDefinition('marketplace.listing.created',AnalyticsEventCategory::Marketplace,365,true,false,true,false,['category','currency']),
            new AnalyticsEventDefinition('marketplace.order.created',AnalyticsEventCategory::Marketplace,365,true,false,true,false,['currency','item_bucket']),
            new AnalyticsEventDefinition('marketplace.purchase.completed',AnalyticsEventCategory::Marketplace,730,true,false,true,false,['currency','payment_provider']),
            new AnalyticsEventDefinition('marketplace.review.created',AnalyticsEventCategory::Marketplace,365,true,false,true,false,['rating_bucket']),
            new AnalyticsEventDefinition('referral.attributed',AnalyticsEventCategory::Referral,365,true,false,true,false,['campaign','source']),
            new AnalyticsEventDefinition('referral.rewarded',AnalyticsEventCategory::Referral,365,true,false,true,false,['campaign','reward_type']),
            new AnalyticsEventDefinition('giveaway.entered',AnalyticsEventCategory::Giveaway,365,true,false,true,false,['eligibility']),
            new AnalyticsEventDefinition('giveaway.won',AnalyticsEventCategory::Giveaway,730,true,false,true,false,['draw_mode']),
            new AnalyticsEventDefinition('moderation.report.created',AnalyticsEventCategory::Moderation,365,true,false,true,true,['content_type','reason']),
            new AnalyticsEventDefinition('moderation.action.applied',AnalyticsEventCategory::Moderation,730,true,false,true,true,['action','content_type']),
            new AnalyticsEventDefinition('moderation.warning.issued',AnalyticsEventCategory::Moderation,730,true,false,true,false,['warning_key','points_bucket']),
        ]);
    }

    public function register(AnalyticsEventDefinition $definition):void
    {
        if(isset($this->definitions[$definition->key])){
            throw new InvalidArgumentException('Analytics event key is already registered: '.$definition->key);
        }
        $this->definitions[$definition->key]=$definition;
    }

    public function require(string $key):AnalyticsEventDefinition
    {
        return $this->definitions[$key]??throw new InvalidArgumentException('Unknown analytics event: '.$key);
    }

    /** @return list<AnalyticsEventDefinition> */
    public function all():array
    {
        return array_values($this->definitions);
    }
}
