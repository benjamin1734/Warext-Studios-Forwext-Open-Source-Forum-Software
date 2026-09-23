<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Ui\Layout\Builder\LayoutAudience;
use Forwext\Core\Ui\Layout\Builder\LayoutDevice;
use Forwext\Core\Ui\Layout\Builder\LayoutDocument;
use Forwext\Core\Ui\Layout\Builder\LayoutDocumentCodec;
use Forwext\Core\Ui\Layout\Builder\LayoutPlacement;
use Forwext\Core\Ui\Layout\Builder\LayoutPlacementCondition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LayoutBuilderDocumentTest extends TestCase
{
    public function testDocumentSupportsMoveReorderDuplicateAndConditionalPreviewData(): void
    {
        $first = new LayoutPlacement(
            LayoutPlacement::generateId(),
            'core.brand-footer',
            'footer.after',
            200,
            true,
            new LayoutPlacementCondition(
                'members.*',
                LayoutAudience::Member,
                [LayoutDevice::Desktop, LayoutDevice::Mobile],
            ),
        );
        $duplicate = new LayoutPlacement(
            LayoutPlacement::generateId(),
            $first->widgetKey,
            'sidebar.primary',
            100,
            true,
            $first->condition,
        );

        $document = new LayoutDocument([$first, $duplicate]);

        self::assertCount(2, $document->placements);
        self::assertSame('footer.after', $document->placements[0]->slotKey);
        self::assertSame('sidebar.primary', $document->placements[1]->slotKey);
        self::assertNotSame(
            $document->placements[0]->placementId->value(),
            $document->placements[1]->placementId->value(),
        );
        self::assertTrue($first->condition->matches('members.profile', true, LayoutDevice::Mobile));
        self::assertFalse($first->condition->matches('members.profile', false, LayoutDevice::Mobile));
        self::assertFalse($first->condition->matches('marketplace.index', true, LayoutDevice::Mobile));
        self::assertFalse($first->condition->matches('members.profile', true, LayoutDevice::Tablet));
    }

    public function testImportExportRoundTripIsVersionedAndLayoutScoped(): void
    {
        $codec = new LayoutDocumentCodec();
        $document = new LayoutDocument([
            new LayoutPlacement(
                LayoutPlacement::generateId(),
                'core.brand-footer',
                'footer.after',
                100,
                true,
                new LayoutPlacementCondition(),
            ),
        ]);

        $json = $codec->export('site.default', $document);
        $imported = $codec->import($json, 'site.default');

        self::assertSame($document->toArray(), $imported->toArray());
        self::assertStringContainsString('"schema": "forwext-layout-builder"', $json);

        $this->expectException(InvalidArgumentException::class);
        $codec->import($json, 'site.other');
    }

    public function testDuplicatePlacementIdsFailClosed(): void
    {
        $id = LayoutPlacement::generateId();
        $condition = new LayoutPlacementCondition();

        $this->expectException(InvalidArgumentException::class);
        new LayoutDocument([
            new LayoutPlacement($id, 'core.brand-footer', 'footer.after', 100, true, $condition),
            new LayoutPlacement($id, 'core.brand-footer', 'main.after', 200, true, $condition),
        ]);
    }
}
