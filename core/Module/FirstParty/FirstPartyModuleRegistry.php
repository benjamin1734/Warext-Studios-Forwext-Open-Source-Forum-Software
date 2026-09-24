<?php

declare(strict_types=1);

namespace Forwext\Core\Module\FirstParty;

use InvalidArgumentException;

final readonly class FirstPartyModuleRegistry
{
    /** @var array<string,FirstPartyModuleDefinition> */
    private array $definitions;

    /** @param list<FirstPartyModuleDefinition> $definitions */
    public function __construct(array $definitions)
    {
        $map = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof FirstPartyModuleDefinition || isset($map[$definition->key])) {
                throw new InvalidArgumentException('First-party module registry contains an invalid or duplicate definition.');
            }
            $map[$definition->key] = $definition;
        }
        ksort($map);
        $this->definitions = $map;
        $this->assertReferences();
        $this->assertAcyclicDependencies();
    }

    public static function withCoreDefaults(): self
    {
        $global = [FirstPartyModuleScope::Global];
        $globalGroup = [FirstPartyModuleScope::Global, FirstPartyModuleScope::Group];
        $globalForum = [FirstPartyModuleScope::Global, FirstPartyModuleScope::Forum];
        $forumGroup = [
            FirstPartyModuleScope::Global,
            FirstPartyModuleScope::Forum,
            FirstPartyModuleScope::Group,
        ];
        $contentScopes = [
            FirstPartyModuleScope::Global,
            FirstPartyModuleScope::Forum,
            FirstPartyModuleScope::Group,
            FirstPartyModuleScope::Thread,
            FirstPartyModuleScope::Post,
        ];

        return new self([
            new FirstPartyModuleDefinition(
                'support',
                'Support Tickets',
                'Destek talepleri, intake, konuşma, SLA ve raporlama.',
                routePrefixes:['support.'],
                settings:[
                    self::flag('ticket_creation_enabled', 'Ticket oluşturma', 'Yeni destek talebi açılmasına izin verir.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_faq_support_drafts',
                    'forwext_support_ticket_relations',
                    'forwext_support_ticket_escalations',
                    'forwext_support_ticket_history',
                    'forwext_support_ticket_messages',
                    'forwext_support_ticket_attachments',
                    'forwext_support_ticket_context_links',
                    'forwext_support_ticket_field_values',
                    'forwext_support_ticket_intake',
                    'forwext_support_submission_rate_limits',
                    'forwext_support_category_fields',
                    'forwext_support_canned_responses',
                    'forwext_support_tickets',
                    'forwext_support_categories',
                ],
            ),
            new FirstPartyModuleDefinition(
                'faq',
                'FAQ',
                'SSS kategorileri, makaleler, arama ve Support entegrasyonu.',
                routePrefixes:['faq.'],
                settings:[
                    self::flag('suggest_before_ticket', 'Ticket öncesi öner', 'Destek talebinden önce uygun FAQ içeriklerini önerir.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_faq_support_drafts',
                    'forwext_faq_helpful_votes',
                    'forwext_faq_article_tags',
                    'forwext_faq_articles',
                    'forwext_faq_categories',
                ],
            ),
            new FirstPartyModuleDefinition(
                'bug-reports',
                'Bug Reports',
                'Hata bildirimleri, diagnostik context, konuşma ve staff workflow.',
                routePrefixes:['bug.'],
                settings:[
                    self::flag('diagnostic_context_enabled', 'Diagnostik context', 'Uygun hata context verilerinin otomatik toplanmasını açar.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_bug_report_duplicates',
                    'forwext_bug_report_messages',
                    'forwext_bug_report_attachments',
                    'forwext_bug_report_intake',
                    'forwext_bug_report_diagnostics',
                    'forwext_bug_report_history',
                    'forwext_bug_reports',
                    'forwext_bug_report_categories',
                ],
            ),
            new FirstPartyModuleDefinition(
                'portfolio',
                'Portfolio',
                'Kullanıcı portfolyo projeleri, medya, yorum ve reaksiyonlar.',
                routePrefixes:['portfolio.'],
                settings:[
                    self::flag('submissions_enabled', 'Proje oluşturma', 'Yeni portfolyo projesi oluşturulmasına izin verir.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_portfolio_history',
                    'forwext_portfolio_reactions',
                    'forwext_portfolio_comments',
                    'forwext_portfolio_project_tags',
                    'forwext_portfolio_media',
                    'forwext_portfolio_projects',
                    'forwext_portfolio_categories',
                ],
                storagePathQueries:[
                    "SELECT storage_path FROM forwext_portfolio_media WHERE storage_path IS NOT NULL AND storage_path<>''",
                ],
            ),
            new FirstPartyModuleDefinition(
                'referral',
                'Referral',
                'Davet/referral kampanyaları, attribution ve reward teslimi.',
                dependencies:['rewards'],
                routePrefixes:['referral.'],
                settings:[
                    self::flag('referrals_enabled', 'Referral katılımı', 'Yeni referral link ve attribution akışını açar.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_referral_rewards',
                    'forwext_referral_attributions',
                    'forwext_referral_clicks',
                    'forwext_referral_links',
                    'forwext_referral_campaigns',
                ],
            ),
            new FirstPartyModuleDefinition(
                'ai-moderation',
                'AI Content Moderation',
                'AI tabanlı içerik değerlendirme, policy, metrics, override ve feedback.',
                settings:[
                    self::flag('auto_review_enabled', 'Otomatik AI incelemesi', 'İçerik pipeline AI değerlendirmesini etkinleştirir.', false, $globalForum),
                ],
                purgeTables:[
                    'forwext_ai_moderation_feedback',
                    'forwext_ai_moderation_metrics',
                    'forwext_ai_moderation_forum_policies',
                    'forwext_ai_moderation_overrides',
                    'forwext_ai_moderation_decisions',
                ],
            ),
            new FirstPartyModuleDefinition(
                'spellcheck',
                'Spell Check',
                'Site ve kullanıcı sözlükleri ile editör yazım denetimi.',
                routePrefixes:['editor.spellcheck','account.spellcheck-dictionary'],
                settings:[
                    self::flag('enabled', 'Yazım denetimi', 'Editör ve içerik pipeline yazım denetimini etkinleştirir.', true, $forumGroup),
                ],
                purgeTables:[
                    'forwext_spellcheck_user_dictionary',
                    'forwext_spellcheck_site_dictionary',
                ],
            ),
            new FirstPartyModuleDefinition(
                'content-manager',
                'User Content Manager',
                'Kullanıcı içerik arama, preview ve güvenli toplu operation sistemi.',
                routePrefixes:['content-manager.'],
                settings:[
                    self::flag('operations_enabled', 'Toplu işlemler', 'Content Manager operation oluşturmayı etkinleştirir.', true, $forumGroup),
                ],
                purgeTables:[
                    'forwext_content_manager_operation_items',
                    'forwext_content_manager_operations',
                ],
            ),
            new FirstPartyModuleDefinition(
                'thread-freshness',
                'Thread Freshness',
                'Konu güncellik policy, state ve review sistemi.',
                routePrefixes:['thread.freshness','moderation.freshness'],
                settings:[
                    self::flag('enabled', 'Konu güncelliği', 'Thread freshness değerlendirmesini etkinleştirir.', true, $globalForum),
                    self::integer('stale_days', 'Eski konu eşiği', 'Scope için önerilen güncellik eşiği.', 30, $globalForum, 1, 3650),
                ],
                purgeTables:[
                    'forwext_thread_freshness_reviews',
                    'forwext_thread_freshness_state',
                    'forwext_thread_freshness_policies',
                ],
            ),
            new FirstPartyModuleDefinition(
                'giveaway',
                'Giveaway',
                'Çekiliş tanımı, eligibility, katılım, draw ve sonuç akışı.',
                dependencies:['rewards'],
                routePrefixes:['giveaway.'],
                settings:[
                    self::flag('entry_enabled', 'Katılım', 'Yeni çekiliş katılımlarını açar.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_giveaway_draw_population',
                    'forwext_giveaway_draws',
                    'forwext_giveaway_entries',
                    'forwext_giveaway_eligible_roles',
                    'forwext_giveaway_eligibility',
                    'forwext_giveaways',
                ],
            ),
            new FirstPartyModuleDefinition(
                'easter-egg',
                'Easter Egg',
                'Route, zaman, grup ve animasyon tabanlı Easter egg sistemi.',
                routePrefixes:['easteregg.'],
                settings:[
                    self::flag('enabled', 'Easter egg runtime', 'Easter egg runtime değerlendirmesini açar.', true, $global),
                ],
                purgeTables:[
                    'forwext_easter_egg_groups',
                    'forwext_easter_eggs',
                    'forwext_easter_egg_settings',
                ],
            ),
            new FirstPartyModuleDefinition(
                'trophies',
                'Trophy / Badge / Achievement',
                'Kural tabanlı trophy tanımı, award ve kullanıcı geçmişi.',
                dependencies:['rewards'],
                routePrefixes:['trophy.'],
                settings:[
                    self::flag('evaluation_enabled', 'Otomatik değerlendirme', 'Otomatik trophy değerlendirmesini açar.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_trophy_runtime_state',
                    'forwext_trophy_history',
                    'forwext_user_trophies',
                    'forwext_trophies',
                ],
            ),
            new FirstPartyModuleDefinition(
                'rewards',
                'Reward Provider',
                'Ortak reward definitions, bindings, grants ve assignment ownership.',
                routePrefixes:['reward.'],
                settings:[
                    self::flag('automatic_grants_enabled', 'Otomatik reward', 'Otomatik reward grant işlemlerini açar.', true, $global),
                ],
                purgeTables:[
                    'forwext_reward_bindings',
                    'forwext_reward_assignment_ownership',
                    'forwext_reward_grants',
                    'forwext_reward_definitions',
                ],
            ),
            new FirstPartyModuleDefinition(
                'promotions',
                'User Promotions',
                'Metric tabanlı otomatik kullanıcı promotion ve reward bağlantısı.',
                dependencies:['rewards'],
                routePrefixes:['promotion.'],
                settings:[
                    self::flag('evaluation_enabled', 'Promotion değerlendirme', 'Otomatik promotion evaluation işlemlerini açar.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_promotion_runtime_state',
                    'forwext_promotions',
                ],
            ),
            new FirstPartyModuleDefinition(
                'marketplace',
                'Marketplace',
                'Listing, category, review, external sale, native order ve digital delivery.',
                routePrefixes:['marketplace.'],
                settings:[
                    self::flag('listing_enabled', 'İlan oluşturma', 'Yeni marketplace listing oluşturulmasını açar.', true, $forumGroup),
                    new FirstPartyModuleSettingDefinition(
                        'purchase_mode',
                        'Satın alma modu',
                        'External redirect, native checkout veya her iki satın alma modunu seçer.',
                        FirstPartyModuleSettingType::String,
                        'both',
                        $globalGroup,
                        allowedStrings:['external','native','both'],
                    ),
                ],
                purgeTables:[
                    'forwext_marketplace_order_item_deliveries',
                    'forwext_marketplace_delivery_settings',
                    'forwext_marketplace_delivery_keys',
                    'forwext_marketplace_order_history',
                    'forwext_marketplace_order_items',
                    'forwext_marketplace_delivery_assets',
                    'forwext_marketplace_orders',
                    'forwext_marketplace_cart_items',
                    'forwext_marketplace_internal_sale_settings',
                    'forwext_marketplace_external_sale_clicks',
                    'forwext_marketplace_external_sale_links',
                    'forwext_marketplace_reviews',
                    'forwext_marketplace_listing_promotions',
                    'forwext_marketplace_listing_custom_values',
                    'forwext_marketplace_custom_fields',
                    'forwext_marketplace_listing_media',
                    'forwext_marketplace_listing_tags',
                    'forwext_marketplace_history',
                    'forwext_marketplace_listings',
                    'forwext_marketplace_categories',
                ],
                storagePathQueries:[
                    "SELECT storage_path FROM forwext_marketplace_listing_media WHERE storage_path IS NOT NULL AND storage_path<>''",
                    "SELECT storage_path FROM forwext_marketplace_delivery_assets WHERE storage_path IS NOT NULL AND storage_path<>''",
                ],
            ),
            new FirstPartyModuleDefinition(
                'payments',
                'Payments',
                'Marketplace native checkout payment attempt, webhook ve refund katmanı.',
                dependencies:['marketplace'],
                routePrefixes:['payment.'],
                settings:[
                    self::flag('native_checkout_enabled', 'Native checkout', 'Native payment attempt başlatılmasına izin verir.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_payment_refunds',
                    'forwext_payment_webhook_events',
                    'forwext_payment_attempts',
                ],
            ),
            new FirstPartyModuleDefinition(
                'subscriptions',
                'Subscriptions / User Upgrades',
                'Plan, purchase, grant, expiry ve entitlement yönetimi.',
                routePrefixes:['subscription.'],
                settings:[
                    self::flag('purchase_enabled', 'Upgrade satın alma', 'Kullanıcı upgrade satın alma akışını etkinleştirir.', true, $globalGroup),
                ],
                purgeTables:[
                    'forwext_subscription_events',
                    'forwext_subscription_webhook_events',
                    'forwext_subscription_purchases',
                    'forwext_user_subscriptions',
                    'forwext_subscription_plan_permissions',
                    'forwext_subscription_plan_roles',
                    'forwext_subscription_plans',
                ],
            ),
            new FirstPartyModuleDefinition(
                'advertising',
                'Advertisements / Notices',
                'Kampanya, notice, placement, targeting ve impression/click analizi.',
                routePrefixes:['advertising.'],
                settings:[
                    self::flag('enabled', 'Reklam/notice gösterimi', 'Runtime reklam ve notice rendering katmanını açar.', true, $contentScopes),
                ],
                purgeTables:[
                    'forwext_ad_events',
                    'forwext_ad_device_targets',
                    'forwext_ad_group_targets',
                    'forwext_ad_forum_targets',
                    'forwext_ad_route_targets',
                    'forwext_ad_campaigns',
                ],
            ),
            new FirstPartyModuleDefinition(
                'analytics',
                'Forum Analytics',
                'Event collection, forum/content/operations/commerce analytics ve report builder.',
                routePrefixes:['analytics.'],
                settings:[
                    self::flag('collection_enabled', 'Analytics toplama', 'First-party analytics event toplamayı etkinleştirir.', true, $contentScopes),
                ],
                purgeTables:[
                    'forwext_analytics_saved_reports',
                    'forwext_search_term_analytics',
                    'forwext_analytics_events',
                ],
            ),
        ]);
    }

    /** @return list<FirstPartyModuleDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function require(string $key): FirstPartyModuleDefinition
    {
        return $this->definitions[$key]
            ?? throw new InvalidArgumentException('Unknown first-party module.');
    }

    public function find(string $key): ?FirstPartyModuleDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function moduleForRoute(string $routeName): ?FirstPartyModuleDefinition
    {
        if (preg_match('/^[a-z][a-z0-9.-]{1,127}$/D', $routeName) !== 1) {
            return null;
        }

        $matches = [];
        foreach ($this->definitions as $definition) {
            foreach ($definition->routePrefixes as $prefix) {
                if ($routeName === $prefix || str_starts_with($routeName, $prefix)) {
                    $matches[] = [$prefix, $definition];
                }
            }
        }
        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn (array $left, array $right): int => strlen($right[0]) <=> strlen($left[0]),
        );

        return $matches[0][1];
    }

    /** @return list<FirstPartyModuleDefinition> */
    public function dependentsOf(string $moduleKey): array
    {
        $this->require($moduleKey);

        return array_values(array_filter(
            $this->definitions,
            static fn (FirstPartyModuleDefinition $definition): bool =>
                in_array($moduleKey, $definition->dependencies, true),
        ));
    }

    /** @return list<FirstPartyModuleDefinition> */
    public function conflictsOf(string $moduleKey): array
    {
        $this->require($moduleKey);
        $result = [];
        foreach ($this->definitions as $definition) {
            if ($definition->key === $moduleKey) {
                foreach ($definition->conflicts as $conflict) {
                    $result[$conflict] = $this->definitions[$conflict];
                }
                continue;
            }
            if (in_array($moduleKey, $definition->conflicts, true)) {
                $result[$definition->key] = $definition;
            }
        }
        ksort($result);

        return array_values($result);
    }

    private function assertReferences(): void
    {
        foreach ($this->definitions as $definition) {
            foreach ([...$definition->dependencies, ...$definition->conflicts] as $key) {
                if (!isset($this->definitions[$key])) {
                    throw new InvalidArgumentException(
                        'First-party module graph references unknown module: ' . $definition->key . ' -> ' . $key,
                    );
                }
            }
        }
    }

    private function assertAcyclicDependencies(): void
    {
        $visiting = [];
        $visited = [];
        foreach (array_keys($this->definitions) as $key) {
            $this->visit($key, $visiting, $visited);
        }
    }

    /** @param array<string,bool> $visiting @param array<string,bool> $visited */
    private function visit(string $key, array &$visiting, array &$visited): void
    {
        if (isset($visited[$key])) {
            return;
        }
        if (isset($visiting[$key])) {
            throw new InvalidArgumentException('First-party module dependency graph contains a cycle.');
        }

        $visiting[$key] = true;
        foreach ($this->definitions[$key]->dependencies as $dependency) {
            $this->visit($dependency, $visiting, $visited);
        }
        unset($visiting[$key]);
        $visited[$key] = true;
    }

    /** @param list<FirstPartyModuleScope> $scopes */
    private static function flag(
        string $key,
        string $label,
        string $description,
        bool $default,
        array $scopes,
    ): FirstPartyModuleSettingDefinition {
        return new FirstPartyModuleSettingDefinition(
            $key,
            $label,
            $description,
            FirstPartyModuleSettingType::Flag,
            $default,
            $scopes,
        );
    }

    /** @param list<FirstPartyModuleScope> $scopes */
    private static function integer(
        string $key,
        string $label,
        string $description,
        int $default,
        array $scopes,
        int $minimum,
        int $maximum,
    ): FirstPartyModuleSettingDefinition {
        return new FirstPartyModuleSettingDefinition(
            $key,
            $label,
            $description,
            FirstPartyModuleSettingType::Integer,
            $default,
            $scopes,
            $minimum,
            $maximum,
        );
    }
}
