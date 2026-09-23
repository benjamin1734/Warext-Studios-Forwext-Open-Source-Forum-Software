<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Analytics\Report\AnalyticsReportDataset;
use Forwext\Core\Analytics\Report\AnalyticsReportDefinition;
use Forwext\Core\Analytics\Report\AnalyticsReportExporter;
use Forwext\Core\Analytics\Report\AnalyticsReportResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AnalyticsReportBuilderTest extends TestCase
{
    public function testDefinitionNormalizesDatasetFiltersAndInclusiveDateRange(): void
    {
        $definition = new AnalyticsReportDefinition(
            AnalyticsReportDataset::CommerceOrders,
            new DateTimeImmutable('2026-09-01 14:00:00', new DateTimeZone('Europe/Istanbul')),
            new DateTimeImmutable('2026-09-30 23:00:00', new DateTimeZone('Europe/Istanbul')),
            ['currency'=>'try','payment_state'=>'paid'],
            5,
        );

        self::assertSame('2026-09-01', $definition->from->format('Y-m-d'));
        self::assertSame('2026-09-30', $definition->to->format('Y-m-d'));
        self::assertSame('2026-10-01', $definition->exclusiveEnd()->format('Y-m-d'));
        self::assertSame(['currency'=>'TRY','payment_state'=>'paid'], $definition->filters);
    }

    public function testDefinitionRejectsFilterOutsideDatasetAllowlist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AnalyticsReportDefinition(
            AnalyticsReportDataset::ForumActivity,
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-02', new DateTimeZone('UTC')),
            ['currency'=>'TRY'],
        );
    }

    public function testDefinitionRejectsMoreThanOneYear(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AnalyticsReportDefinition(
            AnalyticsReportDataset::ContentActivity,
            new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-01-02', new DateTimeZone('UTC')),
        );
    }

    public function testCsvExporterNeutralizesSpreadsheetFormulaCells(): void
    {
        $definition = new AnalyticsReportDefinition(
            AnalyticsReportDataset::ForumActivity,
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
        );
        $result = new AnalyticsReportResult(
            $definition,
            ['day','event_key','count'],
            [['day'=>'2026-09-01','event_key'=>'=2+3','count'=>5]],
            0,
            5,
        );

        $csv = AnalyticsReportExporter::csv($result);

        self::assertStringContainsString("'=2+3", $csv);
        self::assertStringNotContainsString(",=2+3,", $csv);
    }

    public function testJsonPayloadCarriesPrivacyMetadata(): void
    {
        $definition = new AnalyticsReportDefinition(
            AnalyticsReportDataset::ReferralFunnel,
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')),
        );
        $result = new AnalyticsReportResult(
            $definition,
            ['day','campaign_key','state','count'],
            [['day'=>'2026-09-01','campaign_key'=>'community','state'=>'qualified','count'=>8]],
            3,
            5,
        );

        $payload = AnalyticsReportExporter::jsonPayload($result);

        self::assertSame(3, $payload['suppressed_rows']);
        self::assertSame(5, $payload['effective_privacy_min_count']);
        self::assertSame('referral_funnel', $payload['definition']['dataset']);
    }
}
