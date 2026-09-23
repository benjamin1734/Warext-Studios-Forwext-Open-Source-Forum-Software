<?php

declare(strict_types=1);

namespace Forwext\Core\Ui\Appearance\Guide;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AppearanceGuideService
{
    private const MANAGE_PERMISSION = 'appearance.manage';
    private const ADVANCED_PERMISSION = 'appearance.advanced';

    public function __construct(private PermissionAuthorizer $authorizer)
    {
    }

    public function snapshot(
        EntityId $actor,
        AppearanceGuideLevel $mode,
        AppearancePreviewDevice $device,
        string $search,
        string $presetKey,
    ): AppearanceGuideSnapshot {
        $this->require($actor, self::MANAGE_PERMISSION);

        $search = trim($search);
        if (strlen($search) > 80 || preg_match('//u', $search) !== 1) {
            throw new InvalidArgumentException('Appearance guide search is invalid.');
        }

        $presets = self::presets();
        $selected = null;
        foreach ($presets as $preset) {
            if ($preset->key === $presetKey) {
                $selected = $preset;
                break;
            }
        }
        if (!$selected instanceof AppearancePreset) {
            throw new InvalidArgumentException('Appearance preset is unknown.');
        }

        $advancedAllowed = $this->authorizer->allows(
            $actor,
            PermissionKey::fromString(self::ADVANCED_PERMISSION),
        );

        $items = [];
        $access = [];
        foreach (self::items() as $item) {
            if ($mode === AppearanceGuideLevel::Basic && $item->level === AppearanceGuideLevel::Advanced) {
                continue;
            }
            if (!$item->matches($search)) {
                continue;
            }

            $items[] = $item;
            $access[$item->key] = $this->authorizer->allows(
                $actor,
                PermissionKey::fromString($item->requiredPermission),
            );
        }

        usort(
            $items,
            static fn (AppearanceGuideItem $left, AppearanceGuideItem $right): int =>
                [$left->level->value, $left->label, $left->key]
                <=> [$right->level->value, $right->label, $right->key],
        );

        return new AppearanceGuideSnapshot(
            $mode,
            $device,
            $search,
            $selected,
            $presets,
            $items,
            $access,
            $advancedAllowed,
        );
    }

    /** @return list<AppearancePreset> */
    private static function presets(): array
    {
        return [
            AppearancePreset::balanced(),
            AppearancePreset::compact(),
            AppearancePreset::showcase(),
        ];
    }

    /** @return list<AppearanceGuideItem> */
    private static function items(): array
    {
        return [
            new AppearanceGuideItem(
                'appearance.theme.basics',
                'Tema, şablon ve dil',
                'Tema oluştur, parent theme seç, staging revision kaydet ve yayınlamadan önce değişiklik geçmişini incele.',
                AppearanceGuideLevel::Basic,
                '/admin/appearance/themes',
                self::MANAGE_PERMISSION,
                ['tema', 'theme', 'template', 'şablon', 'language', 'dil', 'phrase', 'staging'],
                true,
                'Kalıcı değişiklikler revision geçmişi üzerinden staging rollback ile geri alınabilir.',
            ),
            new AppearanceGuideItem(
                'appearance.layout.basics',
                'Sayfa düzeni ve widget yerleşimi',
                'Header, main, sidebar ve footer slotlarına kayıtlı widgetları yerleştir; cihaz görünümünü yayınlamadan önce kontrol et.',
                AppearanceGuideLevel::Basic,
                '/admin/appearance/layout',
                self::MANAGE_PERMISSION,
                ['layout', 'düzen', 'widget', 'slot', 'sidebar', 'header', 'footer', 'mobil'],
                true,
                'Taslak düzen yayınlanmış düzeni otomatik değiştirmez; güvenli geri dönüş için revision ayrımı korunur.',
            ),
            new AppearanceGuideItem(
                'appearance.theme.revisions',
                'Gelişmiş tema revision ve asset yönetimi',
                'Custom CSS/JavaScript, publish ve rollback işlemlerini revision bazlı ve aynı-origin asset politikasıyla yönet.',
                AppearanceGuideLevel::Advanced,
                '/admin/appearance/themes',
                self::ADVANCED_PERMISSION,
                ['css', 'javascript', 'js', 'revision', 'rollback', 'publish', 'asset'],
                true,
                'Publish öncesi staging diff kullan; rollback yalnız seçilen temanın kendi revision geçmişine uygulanır.',
            ),
            new AppearanceGuideItem(
                'appearance.layout.conditions',
                'Gelişmiş layout koşulları ve yayınlama',
                'Route, audience ve cihaz koşullarını düzenle; import/export ve publish işlemlerini kontrollü kullan.',
                AppearanceGuideLevel::Advanced,
                '/admin/appearance/layout',
                self::ADVANCED_PERMISSION,
                ['condition', 'route', 'audience', 'device', 'import', 'export', 'publish'],
                true,
                'Import ve publish işlemleri gelişmiş yetki ister; taslak revision yayınlanmış görünümden bağımsızdır.',
            ),
        ];
    }

    private function require(EntityId $actor, string $permission): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }
}
