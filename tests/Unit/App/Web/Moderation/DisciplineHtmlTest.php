<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Moderation\DisciplineCapabilities;
use Forwext\App\Web\Moderation\DisciplineHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Moderation\Discipline\DisciplineAction;
use Forwext\Core\Moderation\Discipline\DisciplineActionType;
use Forwext\Core\Moderation\Discipline\DisciplineOverview;
use Forwext\Core\Moderation\Discipline\WarningDefinition;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class DisciplineHtmlTest extends TestCase
{
    public function testNativeDisciplinePageEscapesStoredAndConfiguredContent(): void
    {
        $action = new DisciplineAction(
            EntityId::fromString(str_repeat('a', 32)),
            EntityId::fromString(str_repeat('b', 32)),
            EntityId::fromString(str_repeat('c', 32)),
            DisciplineActionType::Warning,
            ModerationReasonCode::fromString('rules.spam'),
            '<script>alert(1)</script>',
            3,
            'standard',
            [],
            true,
            new DateTimeImmutable('2026-09-18T10:00:00+00:00'),
            new DateTimeImmutable('2026-12-17T10:00:00+00:00'),
        );
        $overview = new DisciplineOverview(
            [new WarningDefinition('standard', '<img src=x onerror=alert(1)>', '<b>unsafe</b>', 3, 90, true)],
            [$action],
        );

        $html = DisciplineHtml::page(
            $overview,
            [$action->userId->value() => '<svg onload=alert(1)>'],
            new BasePath('/community'),
            new DisciplineCapabilities(false, false, false, false, false),
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('<svg onload=alert(1)>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('&lt;svg onload=alert(1)&gt;', $html);
    }

    public function testManagerFormsUseSameOriginBasePathAndExistingModerationScript(): void
    {
        $html = DisciplineHtml::page(
            new DisciplineOverview(
                [new WarningDefinition('minor', 'Hafif', '', 1, 30, true)],
                [],
            ),
            [],
            new BasePath('/community'),
            new DisciplineCapabilities(true, true, true, true, true),
        );

        self::assertStringContainsString('/community/moderation/discipline/actions', $html);
        self::assertStringContainsString('/community/moderation/discipline/warning-definitions', $html);
        self::assertStringContainsString('/community/assets/moderation-workspace.js', $html);
        self::assertStringContainsString('data-moderation-form', $html);
    }
}
