<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Moderation\OversightCapabilities;
use Forwext\App\Web\Moderation\OversightHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Moderation\Oversight\OversightChainState;
use Forwext\Core\Moderation\Oversight\OversightEntry;
use Forwext\Core\Moderation\Oversight\OversightOverview;
use Forwext\Core\Moderation\Oversight\OversightReviewCase;
use Forwext\Core\Moderation\Oversight\OversightReviewStatus;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class OversightHtmlTest extends TestCase
{
    public function testOversightPageEscapesStoredMetadataAndBuildsModerationForms(): void
    {
        $entry = new OversightEntry(
            1,
            EntityId::fromString(str_repeat('a', 32)),
            EntityId::fromString(str_repeat('b', 32)),
            'thread.lock',
            'thread',
            str_repeat('c', 32),
            'req-oversight-ui',
            '{"safe":true}',
            str_repeat('d', 64),
            str_repeat('0', 64),
            str_repeat('e', 64),
            new DateTimeImmutable('2026-09-18T14:30:00+00:00'),
        );
        $case = new OversightReviewCase(
            EntityId::fromString(str_repeat('f', 32)),
            $entry->sourceAuditId,
            EntityId::fromString(str_repeat('d', 32)),
            '<script>alert(1)</script>',
            OversightReviewStatus::Open,
            new DateTimeImmutable('2026-09-18T14:31:00+00:00'),
        );
        $overview = new OversightOverview(null, [$entry], [$case], []);

        $html = OversightHtml::page(
            $overview,
            new BasePath('/community'),
            new OversightCapabilities(true),
        );

        self::assertStringContainsString('/community/moderation/oversight?verify=1', $html);
        self::assertStringContainsString('/community/moderation/oversight/cases', $html);
        self::assertStringContainsString('/community/moderation/oversight/flags', $html);
        self::assertStringContainsString('data-moderation-form', $html);
        self::assertStringContainsString('req-oversight-ui', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
