<?php

declare(strict_types=1);

namespace Forwext\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversNothing]
final class PerformanceObservabilityPolicyTest extends TestCase
{
    public function testLowResourceCiKeepsRequiredQualificationDimensions(): void
    {
        $workflow = $this->read('.github/workflows/performance-observability-qualification.yml');

        foreach ([
            'mysql:8.4',
            'FORWEXT_PERF_THREADS: 800',
            'FORWEXT_PERF_POSTS_PER_THREAD: 8',
            'FORWEXT_PERF_ITERATIONS: 25',
            'memory_limit=128M',
            'opcache.enable_cli=1',
            'performance-observability.php',
            'performance-observability.json',
        ] as $contract) {
            self::assertStringContainsString($contract, $workflow);
        }
    }

    public function testProbeKeepsNPlusOneLatencyCacheQueueMemoryAndOpcacheGates(): void
    {
        $probe = $this->read('tools/quality/performance-observability.php');

        foreach ([
            'n_plus_one_query_budget',
            'forum_listing',
            'thread_listing',
            'post_page',
            'search',
            'p95',
            'cache',
            'queue',
            'peak_memory_bytes',
            'opcache_enabled',
            'shared-hosting-low-resource',
        ] as $contract) {
            self::assertStringContainsString($contract, $probe);
        }
    }

    public function testArchitectureDocumentCoversRoadmapPerformanceScope(): void
    {
        $document = $this->read('docs/architecture/performance-observability-qualification.md');

        foreach ([
            'Large seeded dataset',
            'Forum, thread and post load',
            'Native search load',
            'Slow query and N+1',
            'Cache and queue observability',
            'Memory and OPcache',
            'Shared-hosting low-resource profile',
        ] as $section) {
            self::assertStringContainsString($section, $document);
        }
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read performance qualification file: ' . $relativePath);
        }

        return $contents;
    }
}
