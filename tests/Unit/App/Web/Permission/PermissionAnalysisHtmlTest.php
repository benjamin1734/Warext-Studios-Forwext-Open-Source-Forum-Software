<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Permission;

use Forwext\App\Web\Permission\PermissionAnalysisHtml;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysis;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysisLayer;
use Forwext\Core\Domain\Access\Permission\Analyzer\PermissionAnalysisLayerState;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use PHPUnit\Framework\TestCase;

final class PermissionAnalysisHtmlTest extends TestCase
{
    public function testRendersAccessibleLayeredExplanation(): void
    {
        $analysis = new PermissionAnalysis(
            PermissionKey::fromString('forum.thread.create'),
            null,
            false,
            null,
            'implicit_deny',
            'Denied because no applicable rule granted this permission.',
            [
                new PermissionAnalysisLayer(
                    'global_user',
                    'Global user override',
                    PermissionAnalysisLayerState::NoRule,
                    'No direct override matched.',
                    [],
                ),
                new PermissionAnalysisLayer(
                    'fallback',
                    'Secure fallback',
                    PermissionAnalysisLayerState::Denied,
                    'No higher layer resolved the permission, so the secure default is deny.',
                    [],
                ),
            ],
        );

        $html = (new PermissionAnalysisHtml())->render($analysis);

        self::assertStringContainsString('aria-labelledby="permission-analysis-title"', $html);
        self::assertStringContainsString('<strong>Denied</strong>', $html);
        self::assertStringContainsString('<code>forum.thread.create</code>', $html);
        self::assertStringContainsString('data-state="no_rule"', $html);
        self::assertStringContainsString('data-state="denied"', $html);
        self::assertStringContainsString('Secure fallback', $html);
    }
}
