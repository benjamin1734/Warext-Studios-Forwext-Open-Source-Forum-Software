<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Moderation\CoreAuditHtml;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class CoreAuditHtmlTest extends TestCase
{
    public function testAuditPageEscapesSnapshotAndBuildsSameBasePathFilters(): void
    {
        $event = new AuditEvent(
            EntityId::fromString(str_repeat('a', 32)),
            AuditScope::Administration,
            EntityId::fromString(str_repeat('b', 32)),
            AuditAction::fromString('forum.metadata.configuration.save'),
            'forum.metadata',
            str_repeat('c', 32),
            null,
            null,
            AuditRequestId::fromString('req-audit-ui'),
            ['label' => '<script>alert(1)</script>'],
            ['label' => '<img src=x onerror=alert(1)>'],
            new DateTimeImmutable('2026-09-18T13:00:00+00:00'),
        );

        $html = CoreAuditHtml::page(
            [$event],
            new BasePath('/community'),
            str_repeat('b', 32),
            null,
        );

        self::assertStringContainsString('/community/moderation/audit', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        self::assertStringContainsString('req-audit-ui', $html);
    }
}
