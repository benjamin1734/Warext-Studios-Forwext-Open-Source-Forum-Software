<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\Access\Permission;

final class FirstPartyPermissionCatalog
{
    /** @return list<PermissionCatalogEntry> */
    public static function entries(): array
    {
        return [
            self::flag('forum.view', 'View forum content.'),
            self::flag('forum.thread.create', 'Create forum threads.'),
            self::flag('forum.post.create', 'Create forum posts and replies.'),
            self::numeric('forum.content.daily_limit', 'Maximum daily forum content creation baseline.'),

            self::flag('search.use', 'Use permission-aware native search.'),
            self::flag('report.create', 'Report accessible content for moderation review.'),

            self::flag('moderation.access', 'Access moderation workspace surfaces.'),
            self::flag('moderation.manage', 'Perform moderation management actions.'),
            self::flag('moderation.discipline.view', 'View warning, restriction, suspension and ban records.'),
            self::flag('moderation.warning.issue', 'Issue configured warning definitions to users.'),
            self::flag('moderation.warning.manage', 'Manage warning definitions and point/expiry defaults.'),
            self::flag('moderation.restriction.manage', 'Apply posting and content restrictions.'),
            self::flag('moderation.ban.manage', 'Apply temporary suspensions and temporary/permanent bans.'),
            self::flag('moderation.discipline.revoke', 'Revoke active discipline actions.'),
            self::flag('moderation.abuse.view', 'View anti-spam and abuse events.'),
            self::flag('moderation.abuse.manage_rules', 'Manage automated anti-abuse rules.'),
            self::flag('moderation.abuse.cleanup', 'Run abuse cleanup through normal content moderation permissions.'),
            self::flag('audit.view', 'View authorized core audit records.'),
            self::flag('audit.review', 'Review independent moderation audit cases and anomaly flags.'),
            self::flag('audit.export', 'Export authorized independent moderation audit data.'),
            self::flag('acp.access', 'Access the administration control panel.'),
            self::flag('acp.manage', 'Perform administration management actions.'),

            self::flag('profile.custom_url.use', 'Choose and use a custom profile URL.'),
            self::flag('profile.music.use', 'Use profile music.'),
            self::flag('profile.music.upload', 'Upload profile music.'),
            self::flag('profile.music.external', 'Use approved external profile music sources.'),
            self::flag('profile.music.autoplay', 'Request profile music autoplay where client policy permits.'),
            self::flag('profile.music.moderate', 'Moderate profile music.'),

            self::flag('support.ticket.create', 'Create support tickets.'),
            self::flag('support.ticket.view_own', 'View own support tickets.'),
            self::flag('support.ticket.reply_own', 'Reply to own support tickets.'),
            self::flag('support.ticket.view_all', 'View support tickets from all users.'),
            self::flag('support.ticket.manage', 'Manage support tickets.'),
            self::flag('support.ticket.reply_all', 'Reply publicly to support tickets from all users.'),
            self::flag('support.ticket.internal_note', 'Add staff-only internal notes to support tickets.'),
            self::flag('support.ticket.assign', 'Assign or unassign support tickets.'),
            self::flag('support.ticket.escalate', 'Escalate active support tickets.'),
            self::flag('support.ticket.merge', 'Merge eligible support tickets from the same requester.'),
            self::flag('support.ticket.split', 'Split public support messages into new tickets.'),
            self::flag('support.canned_response.manage', 'Manage reusable support canned responses.'),
            self::flag('support.faq_draft.suggest', 'Suggest FAQ drafts from public staff ticket replies.'),
            self::flag('support.report.view', 'View support dashboard, SLA and category reporting.'),
            self::flag('support.audit.view', 'View support-scoped central audit records.'),
            self::flag('faq.view', 'View FAQ content.'),
            self::flag('faq.manage', 'Manage FAQ categories, questions and answers.'),

            self::flag('bug.report.create', 'Create bug reports.'),
            self::flag('bug.report.view_own', 'View own bug reports.'),
            self::flag('bug.report.view_all', 'View bug reports from all users.'),
            self::flag('bug.report.manage', 'Manage bug reports and duplicate workflows.'),
            self::flag('bug.report.assign', 'Assign or unassign bug reports.'),
            self::flag('bug.report.reply_own', 'Add follow-up information to own bug reports.'),
            self::flag('bug.report.reply_all', 'Reply to bug reports from all users.'),
            self::flag('bug.report.export', 'Export authorized bug-report data.'),
            self::flag('bug.audit.view', 'View centralized bug-report audit records.'),

            self::flag('portfolio.view', 'View portfolio content.'),
            self::flag('portfolio.create', 'Create portfolio entries.'),
            self::flag('portfolio.manage_own', 'Manage own portfolio entries.'),
            self::flag('portfolio.manage_all', 'Manage all portfolio entries.'),
            self::flag('portfolio.comment.create', 'Comment on visible portfolio projects.'),
            self::flag('portfolio.reaction.use', 'React to visible portfolio projects.'),
            self::flag('invite.create', 'Create invitation links or codes.'),
            self::flag('invite.view_own', 'View own invitation activity.'),
            self::flag('invite.manage', 'Manage invitation policy and activity.'),
            self::flag('referral.view_own', 'View own referral attribution and rewards.'),
            self::flag('referral.manage', 'Manage referral campaigns, attribution and anti-fraud actions.'),

            self::flag('ai.assist.use', 'Use AI writing assistance.'),
            self::flag('ai.moderation.use', 'Run AI moderation checks.'),
            self::flag('ai.moderation.override', 'Override AI moderation decisions for exact content fingerprints.'),
            self::flag('ai.manage', 'Manage AI policies, prompts and providers.'),
            self::flag('spellcheck.use', 'Use writing and spelling assistance.'),
            self::flag('spellcheck.dictionary.manage_own', 'Manage own spellcheck dictionary entries.'),
            self::flag('spellcheck.dictionary.manage_site', 'Manage the site spellcheck dictionary.'),
            self::flag('content_manager.access', 'Access user content management tools.'),
            self::flag('content_manager.execute', 'Execute authorized bulk content management actions.'),
            self::flag('forum.thread.freshness.renew_own', 'Renew freshness for an authored thread in an authorized forum.'),
            self::flag('forum.thread.freshness.renew_any', 'Renew or reopen freshness for any thread in an authorized forum.'),
            self::flag('forum.thread.freshness.review', 'Review stale-thread freshness cases in an authorized forum.'),
            self::flag('forum.thread.freshness.manage_policy', 'Manage per-forum thread freshness policy.'),

            self::flag('giveaway.view', 'View giveaways.'),
            self::flag('giveaway.enter', 'Enter eligible giveaways.'),
            self::flag('giveaway.create', 'Create giveaways.'),
            self::flag('giveaway.manage', 'Manage giveaways, eligibility and winner workflows.'),
            self::flag('easteregg.manage', 'Manage Easter Egg definitions and activation rules.'),
            self::flag('trophy.view', 'View trophy, badge and achievement information.'),
            self::flag('trophy.manage', 'Manage trophy, badge and achievement definitions.'),
            self::flag('trophy.award', 'Award or revoke authorized trophies and badges.'),
            self::flag('promotion.manage', 'Manage automatic user promotion rules.'),
            self::flag('reward.manage', 'Manage shared reward-provider policies and grants.'),

            self::flag('marketplace.category.manage', 'Manage marketplace categories and custom fields.'),
            self::flag('marketplace.listing.view', 'View marketplace listings.'),
            self::flag('marketplace.listing.create', 'Create marketplace listings.'),
            self::flag('marketplace.listing.manage_own', 'Manage own marketplace listings.'),
            self::flag('marketplace.listing.manage_all', 'Manage all marketplace listings.'),
            self::flag('marketplace.feature.manage', 'Manage featured and pinned marketplace placement.'),
            self::flag('marketplace.review.create', 'Create or update marketplace reviews.'),
            self::flag('marketplace.review.manage', 'Moderate marketplace reviews.'),
            self::flag('marketplace.external_link.use', 'Use approved external-sale links on marketplace listings.'),
            self::flag('marketplace.internal_purchase.use', 'Use native marketplace checkout and purchase flows.'),
            self::flag('marketplace.purchase', 'Purchase marketplace listings.'),
            self::flag('marketplace.order.manage', 'Manage marketplace orders and delivery workflows.'),
            self::flag('marketplace.delivery.manage_own', 'Configure and fulfill delivery for owned marketplace listings and sales.'),
            self::flag('marketplace.delivery.manage_all', 'Manage marketplace delivery configuration and fulfillment for all sellers.'),
            self::flag('payment.manage', 'Manage payment-provider configuration and payment operations.'),
            self::flag('payment.refund', 'Issue authorized refunds or cancellations.'),
            self::flag('subscription.view', 'View available subscription and user-upgrade plans.'),
            self::flag('subscription.purchase', 'Purchase eligible subscription or user-upgrade plans.'),
            self::flag('subscription.manage_own', 'Manage own subscription and upgrade state.'),
            self::flag('subscription.manage_all', 'Manage all subscription and user-upgrade plans and assignments.'),
            self::flag('ads.manage', 'Manage advertising placements, targeting and frequency controls.'),
            self::flag('notice.manage', 'Manage site notices and announcements.'),

            self::flag('analytics.view_own', 'View own privacy-safe analytics.'),
            self::flag('analytics.view_forum', 'View authorized forum-level analytics.'),
            self::flag('analytics.view_site', 'View site-wide analytics and business intelligence.'),
            self::flag('analytics.export', 'Export authorized analytics reports.'),

            self::flag('appearance.manage', 'Manage site appearance and theme configuration.'),
            self::flag('appearance.advanced', 'Use advanced appearance customization surfaces.'),
            self::flag('api.use', 'Use authenticated first-party REST API capabilities.'),
            self::flag('api.token.manage_own', 'Manage own API tokens where enabled.'),
            self::flag('api.manage', 'Manage API, token and webhook platform policy.'),
        ];
    }

    /** @return list<PermissionNamespace> */
    public static function namespaces(): array
    {
        /** @var array<string, PermissionNamespace> $namespaces */
        $namespaces = [];
        foreach (self::entries() as $entry) {
            $namespace = $entry->namespace();
            $namespaces[$namespace->value()] = $namespace;
        }

        ksort($namespaces, SORT_STRING);
        return array_values($namespaces);
    }

    private static function flag(string $key, string $description): PermissionCatalogEntry
    {
        return new PermissionCatalogEntry(
            PermissionKey::fromString($key),
            PermissionValueType::Flag,
            $description,
        );
    }

    private static function numeric(string $key, string $description): PermissionCatalogEntry
    {
        return new PermissionCatalogEntry(
            PermissionKey::fromString($key),
            PermissionValueType::Numeric,
            $description,
        );
    }
}
