<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Moderation;

use DateTimeImmutable;
use Forwext\App\Web\Report\ReportHtml;
use Forwext\App\Web\Report\ReportRequestGuard;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Canonical\CanonicalUrl;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Core\Moderation\Report\NotificationReportNotifier;
use Forwext\Core\Moderation\Report\ReportReason;
use Forwext\Core\Moderation\Report\ReportStatus;
use Forwext\Core\Moderation\Report\ReportableContent;
use Forwext\Core\Moderation\Report\ReportableContentRegistry;
use Forwext\Core\Moderation\Report\ReportableContentResolver;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceItem;
use Forwext\Core\Moderation\Workspace\ModerationWorkspaceSection;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReportSystemTest extends TestCase
{
    public function testReportableRegistryOnlyResolvesExplicitlyRegisteredTypes(): void
    {
        $registry = new ReportableContentRegistry([new FixedReportableResolver('thread')]);
        $viewer = EntityId::fromString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $target = EntityId::fromString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        self::assertSame('thread', $registry->resolve('thread', $viewer, $target)?->targetType);
        self::assertNull($registry->resolve('post', $viewer, $target));
        self::assertSame(['thread'], $registry->targetTypes());
    }

    public function testReportableRegistryRejectsDuplicateTargetTypeOwnership(): void
    {
        $registry = new ReportableContentRegistry([new FixedReportableResolver('thread')]);
        $this->expectException(InvalidArgumentException::class);
        $registry->register(new FixedReportableResolver('thread'));
    }

    public function testReportRequestGuardRequiresCustomHeaderAndRejectsForeignOrigin(): void
    {
        $guard = new ReportRequestGuard(new CanonicalUrl('https://forum.example.com'));
        $valid = new Request(
            HttpMethod::Post,
            '/reports',
            new HeaderBag([
                'X-Forwext-Report' => '1',
                'Origin' => 'https://forum.example.com',
                'Sec-Fetch-Site' => 'same-origin',
            ]),
        );
        $foreign = new Request(
            HttpMethod::Post,
            '/reports',
            new HeaderBag([
                'X-Forwext-Report' => '1',
                'Origin' => 'https://evil.example',
                'Sec-Fetch-Site' => 'cross-site',
            ]),
        );
        $missingHeader = new Request(HttpMethod::Post, '/reports');

        self::assertTrue($guard->allows($valid));
        self::assertFalse($guard->allows($foreign));
        self::assertFalse($guard->allows($missingHeader));
    }

    public function testReportFormEscapesTargetAndReasonLabels(): void
    {
        $html = ReportHtml::form(
            new ReportableContent(
                'thread',
                EntityId::fromString('cccccccccccccccccccccccccccccccc'),
                '<script>alert(1)</script>',
            ),
            [new ReportReason('spam', '<img src=x onerror=alert(1)>', '', 10, true)],
            new BasePath(''),
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('data-report-form', $html);
    }

    public function testReportNotificationDefinitionsAreRegisteredAsFirstPartyTypes(): void
    {
        $registry = new NotificationRegistry();
        NotificationReportNotifier::registerDefinitions($registry);

        self::assertTrue($registry->has(NotificationReportNotifier::RECEIVED_TYPE));
        self::assertTrue($registry->has(NotificationReportNotifier::ASSIGNED_TYPE));
        self::assertTrue($registry->has(NotificationReportNotifier::STATUS_TYPE));
        self::assertCount(3, $registry->all());
    }

    public function testReportMigrationIsRegisteredAndStatusesHaveTerminalBoundary(): void
    {
        $ids = array_map(
            static fn ($migration): string => $migration->id()->value(),
            CoreMigrationRegistry::all(),
        );

        self::assertContains('20260918002000_report_system', $ids);
        self::assertTrue(ReportStatus::Open->isActive());
        self::assertTrue(ReportStatus::InReview->isActive());
        self::assertFalse(ReportStatus::Resolved->isActive());
        self::assertFalse(ReportStatus::Rejected->isActive());
    }

    public function testWorkspaceActionPathCannotBecomeAnExternalUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModerationWorkspaceItem(
            ModerationWorkspaceSection::Reports,
            'report.group',
            'dddddddddddddddddddddddddddddddd',
            'Report',
            'open',
            new DateTimeImmutable('2026-09-18T00:00:00+00:00'),
            actionPath: '//evil.example/report',
        );
    }
}

final readonly class FixedReportableResolver implements ReportableContentResolver
{
    public function __construct(private string $type)
    {
    }

    public function targetType(): string
    {
        return $this->type;
    }

    public function resolve(EntityId $viewerUserId, EntityId $targetId): ?ReportableContent
    {
        return new ReportableContent($this->type, $targetId, 'Visible target');
    }
}
