<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Navigation;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AdminNavigationRegistry
{
    /** @var list<AdminNavigationItem> */
    private array $items;

    /** @param list<AdminNavigationItem> $items */
    public function __construct(
        private PermissionAuthorizer $authorizer,
        array $items,
    ) {
        $keys = [];
        foreach ($items as $item) {
            if (!$item instanceof AdminNavigationItem) {
                throw new InvalidArgumentException('Admin navigation registry contains an invalid item.');
            }
            if (isset($keys[$item->key])) {
                throw new InvalidArgumentException('Admin navigation item keys must be unique.');
            }
            $keys[$item->key] = true;
        }
        usort(
            $items,
            static fn (AdminNavigationItem $left, AdminNavigationItem $right): int => [
                $left->section->sortOrder(),
                $left->label,
                $left->key,
            ] <=> [
                $right->section->sortOrder(),
                $right->label,
                $right->key,
            ],
        );
        $this->items = array_values($items);
    }

    public static function withCoreDefaults(PermissionAuthorizer $authorizer): self
    {
        return new self($authorizer, [
            new AdminNavigationItem(
                'admin.users',
                'Kullanıcı Yönetimi',
                'Kullanıcı arama, hesap geçmişi ve doğrudan grup/rol atamaları.',
                AdminNavigationSection::ModerationSupport,
                '/admin/users',
                ['acp.manage'],
                ['user', 'kullanıcı', 'account', 'group', 'role', 'history'],
            ),
            new AdminNavigationItem(
                'admin.access',
                'Gruplar, Roller ve Yetkiler',
                'User groups, staff/custom roles, role banner görünümü ve permission analyzer.',
                AdminNavigationSection::ModerationSupport,
                '/admin/access',
                ['acp.manage'],
                ['group', 'role', 'banner', 'permission', 'analyzer', 'yetki'],
            ),
            new AdminNavigationItem(
                'admin.forums',
                'Forum ve Node Yönetimi',
                'Kategori, forum, page/link node hiyerarşisi ve forum davranış ayarları.',
                AdminNavigationSection::ModerationSupport,
                '/admin/forums',
                ['acp.manage'],
                ['forum', 'node', 'category', 'visibility', 'approval'],
            ),
            new AdminNavigationItem(
                'admin.content',
                'İçerik Yönetimi',
                'Thread/post özetleri, approval queue, content manager ve freshness araçları.',
                AdminNavigationSection::ModerationSupport,
                '/admin/content',
                ['acp.manage'],
                ['content', 'thread', 'post', 'approval', 'freshness'],
            ),
            new AdminNavigationItem(
                'admin.moderation',
                'Moderasyon Çalışma Alanı',
                'Raporlar, onay kuyruğu, disiplin, anti-abuse, görevler ve bağımsız denetim.',
                AdminNavigationSection::ModerationSupport,
                '/admin/moderation',
                ['acp.manage'],
                ['report', 'approval', 'warning', 'ban', 'audit', 'oversight', 'abuse', 'görev'],
            ),
            new AdminNavigationItem(
                'admin.support',
                'Destek Talepleri',
                'Aktif destek kuyruğu, atamalar, SLA ve personel raporlaması.',
                AdminNavigationSection::ModerationSupport,
                '/support/staff',
                ['support.ticket.view_all'],
                ['ticket', 'destek', 'sla', 'talep', 'assignment'],
            ),
            new AdminNavigationItem(
                'admin.bugs',
                'Hata Bildirimleri',
                'Hata kuyruğu, atama, duplicate yönetimi, export ve audit görünümü.',
                AdminNavigationSection::ModerationSupport,
                '/bugs/staff',
                ['bug.report.view_all'],
                ['bug', 'hata', 'duplicate', 'severity', 'report'],
            ),
            new AdminNavigationItem(
                'admin.marketplace.categories',
                'Marketplace Kategorileri',
                'Marketplace kategori, alt kategori ve custom field yapılandırması.',
                AdminNavigationSection::Commerce,
                '/admin/marketplace/categories',
                ['marketplace.category.manage'],
                ['marketplace', 'kategori', 'category', 'custom field'],
            ),
            new AdminNavigationItem(
                'admin.payments',
                'Ödemeler',
                'Ödeme denemeleri, provider durumu ve yetkili refund işlemleri.',
                AdminNavigationSection::Commerce,
                '/admin/payments',
                ['payment.manage'],
                ['payment', 'ödeme', 'refund', 'provider'],
            ),
            new AdminNavigationItem(
                'admin.subscriptions',
                'Abonelikler ve User Upgrades',
                'Planlar, süreler, rol/yetki bağları, grant, renewal ve expiry yönetimi.',
                AdminNavigationSection::Commerce,
                '/admin/subscriptions',
                ['subscription.manage_all'],
                ['subscription', 'abonelik', 'upgrade', 'plan', 'renewal', 'expiry'],
            ),
            new AdminNavigationItem(
                'admin.advertising',
                'Reklam ve Notice',
                'Placement, hedefleme, kampanya, notice ve performans görünümü.',
                AdminNavigationSection::Commerce,
                '/admin/advertising',
                ['ads.manage', 'notice.manage'],
                ['advertising', 'reklam', 'notice', 'placement', 'campaign'],
            ),
            new AdminNavigationItem(
                'admin.analytics',
                'Forum Analytics',
                'Forum büyümesi, aktif kullanıcılar, içerik ve ana forum metrikleri.',
                AdminNavigationSection::Analytics,
                '/admin/analytics',
                ['analytics.view_site', 'analytics.view_forum'],
                ['analytics', 'dau', 'mau', 'growth', 'forum'],
            ),
            new AdminNavigationItem(
                'admin.analytics.content',
                'İçerik ve Engagement',
                'İçerik performansı, etkileşimler ve arama davranışı analizleri.',
                AdminNavigationSection::Analytics,
                '/admin/analytics/content',
                ['analytics.view_site', 'analytics.view_content'],
                ['content', 'engagement', 'search', 'reaction'],
            ),
            new AdminNavigationItem(
                'admin.analytics.operations',
                'Operasyon Analizleri',
                'Moderasyon, destek ve hata süreçlerinin operasyonel metrikleri.',
                AdminNavigationSection::Analytics,
                '/admin/analytics/operations',
                ['analytics.view_site', 'analytics.view_operations'],
                ['operations', 'moderation', 'support', 'bug', 'sla'],
            ),
            new AdminNavigationItem(
                'admin.analytics.commerce',
                'Ticaret Analizleri',
                'Marketplace, gelir, referral ve kampanya dönüşüm metrikleri.',
                AdminNavigationSection::Analytics,
                '/admin/analytics/commerce',
                ['analytics.view_site', 'analytics.view_commerce'],
                ['commerce', 'revenue', 'gmv', 'marketplace', 'referral'],
            ),
            new AdminNavigationItem(
                'admin.analytics.reports',
                'Rapor Builder',
                'Filtrelenebilir, kaydedilebilir ve yetki kontrollü analytics raporları.',
                AdminNavigationSection::Analytics,
                '/admin/analytics/reports',
                ['analytics.view_site', 'analytics.report.use'],
                ['report', 'rapor', 'export', 'saved report'],
            ),
            new AdminNavigationItem(
                'admin.appearance',
                'Appearance Studio',
                'Basit/Gelişmiş görünüm, güvenli presetler, arama, preview ve setup assistant.',
                AdminNavigationSection::Appearance,
                '/admin/appearance',
                ['appearance.manage'],
                ['appearance', 'görünüm', 'preset', 'preview', 'tema', 'layout'],
            ),
            new AdminNavigationItem(
                'admin.themes',
                'Tema, Şablon ve Dil',
                'Base/child theme, staging, phrase, custom CSS/JS, diff ve revision yönetimi.',
                AdminNavigationSection::Appearance,
                '/admin/appearance/themes',
                ['appearance.manage'],
                ['theme', 'tema', 'template', 'şablon', 'language', 'phrase', 'css'],
            ),
            new AdminNavigationItem(
                'admin.layout',
                'Layout Builder',
                'Widget slotları, device/audience koşulları, taslak ve yayınlanan layout.',
                AdminNavigationSection::Appearance,
                '/admin/appearance/layout',
                ['appearance.manage'],
                ['layout', 'widget', 'slot', 'sidebar', 'header', 'footer'],
            ),
            new AdminNavigationItem(
                'admin.modules',
                'First-party Modüller',
                'Enabled/disabled/uninstalled lifecycle, dependency graph, scoped settings ve keep/delete data politikası.',
                AdminNavigationSection::Community,
                '/admin/modules',
                ['module.manage'],
                ['module', 'modül', 'lifecycle', 'dependency', 'conflict', 'uninstall', 'scope'],
            ),
            new AdminNavigationItem(
                'admin.integrations',
                'Sistem ve Entegrasyonlar',
                'Mail, OAuth, Turnstile, AI, storage, cache, queue, search, realtime, API/webhook hazırlığı ve secret yönetimi.',
                AdminNavigationSection::System,
                '/admin/integrations',
                ['integration.manage'],
                ['integration', 'entegrasyon', 'mail', 'smtp', 'oauth', 'turnstile', 'ai', 'storage', 's3', 'cache', 'redis', 'queue', 'search', 'realtime', 'api', 'webhook', 'secret'],
            ),
            new AdminNavigationItem(
                'admin.system.operations',
                'Sistem Operasyon Merkezi',
                'Health/capabilities, logs, failed jobs, cron, integrity, backups, maintenance ve repair araçları.',
                AdminNavigationSection::System,
                '/admin/system/operations',
                [
                    'system.health.view',
                    'system.logs.view',
                    'system.jobs.manage',
                    'system.backup.manage',
                    'system.maintenance.manage',
                    'system.repair.manage',
                ],
                ['health', 'capability', 'log', 'job', 'queue', 'cron', 'integrity', 'backup', 'maintenance', 'repair'],
            ),
            new AdminNavigationItem(
                'admin.rewards',
                'Ödül Provider Sistemi',
                'Ortak reward tanımları, provider hedefleri, binding ve retry işlemleri.',
                AdminNavigationSection::Community,
                '/admin/rewards',
                ['reward.manage'],
                ['reward', 'ödül', 'provider', 'grant', 'binding'],
            ),
            new AdminNavigationItem(
                'admin.promotions',
                'User Promotions',
                'Kural bazlı otomatik kullanıcı yükseltmeleri ve ödül bağları.',
                AdminNavigationSection::Community,
                '/admin/promotions',
                ['promotion.manage'],
                ['promotion', 'terfi', 'otomatik', 'rule', 'reward'],
            ),
            new AdminNavigationItem(
                'admin.trophies',
                'Trophy, Rozet ve Başarım',
                'Kural tabanlı trophy tanımları, değerlendirme ve manuel award araçları.',
                AdminNavigationSection::Community,
                '/admin/trophies',
                ['trophy.manage', 'trophy.award'],
                ['trophy', 'rozet', 'başarım', 'achievement', 'award'],
            ),
            new AdminNavigationItem(
                'admin.easter-eggs',
                'Easter Egg',
                'Tetikleyici, tarih, route, grup görünürlüğü ve güvenli global kapatma.',
                AdminNavigationSection::Community,
                '/admin/easter-eggs',
                ['easteregg.manage'],
                ['easter egg', 'trigger', 'route', 'badge', 'animation'],
            ),
        ]);
    }

    /** @return list<AdminNavigationItem> */
    public function visible(EntityId $actor): array
    {
        return array_values(array_filter(
            $this->items,
            fn (AdminNavigationItem $item): bool => $this->isAccessible($actor, $item),
        ));
    }

    /** @return list<AdminNavigationItem> */
    public function search(EntityId $actor, string $query): array
    {
        return array_values(array_filter(
            $this->visible($actor),
            static fn (AdminNavigationItem $item): bool => $item->matches($query),
        ));
    }

    public function accessible(EntityId $actor, string $key): ?AdminNavigationItem
    {
        $item = $this->find($key);

        return $item !== null && $this->isAccessible($actor, $item) ? $item : null;
    }

    public function find(string $key): ?AdminNavigationItem
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }

        return null;
    }

    private function isAccessible(EntityId $actor, AdminNavigationItem $item): bool
    {
        foreach ($item->requiredAnyPermissions as $permission) {
            if ($this->authorizer->allows($actor, PermissionKey::fromString($permission))) {
                return true;
            }
        }

        return false;
    }
}
