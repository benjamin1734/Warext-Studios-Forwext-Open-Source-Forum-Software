<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Moderation\AbuseCapabilities;
use Forwext\App\Web\Moderation\AbuseHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Moderation\Abuse\AbuseAction;
use Forwext\Core\Moderation\Abuse\AbuseEvent;
use Forwext\Core\Moderation\Abuse\AbuseEventType;
use Forwext\Core\Moderation\Abuse\AbuseOverview;
use Forwext\Core\Moderation\Abuse\AbuseRule;
use Forwext\Core\Moderation\Abuse\AbuseSignal;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class AbuseHtmlTest extends TestCase
{
    public function testPageEscapesRuleLabelsAndUsesSameOriginModerationForms(): void
    {
        $event = new AbuseEvent(
            EntityId::fromString(str_repeat('a', 32)),
            AbuseEventType::Post,
            AbuseAction::Review,
            ['post.content.review'],
            EntityId::fromString(str_repeat('b', 32)),
            'forum.post',
            EntityId::fromString(str_repeat('c', 32)),
            null,
            str_repeat('d', 64),
            str_repeat('e', 64),
            str_repeat('f', 64),
            new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
        );
        $overview = new AbuseOverview([
            new AbuseRule(
                'post.content.review',
                '<script>alert(1)</script>',
                AbuseEventType::Post,
                AbuseSignal::Content,
                2,
                3600,
                AbuseAction::Review,
                true,
            ),
        ], [$event]);

        $html = AbuseHtml::page(
            $overview,
            new BasePath('/community'),
            new AbuseCapabilities(true, true),
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('/community/moderation/abuse/rules', $html);
        self::assertStringContainsString('/community/moderation/abuse/events', $html);
        self::assertStringContainsString('data-moderation-form', $html);
        self::assertStringContainsString('/community/assets/moderation-workspace.js', $html);
    }
}
