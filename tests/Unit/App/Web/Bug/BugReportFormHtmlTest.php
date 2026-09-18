<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Bug;

use Forwext\App\Web\Bug\BugReportFormHtml;
use Forwext\Core\Bug\Report\BugReportCategory;
use Forwext\Core\Bug\Report\BugReportSeverity;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class BugReportFormHtmlTest extends TestCase
{
    public function testFormContainsRequiredBugFieldsInlineAttachmentsAndEscapesDynamicText(): void
    {
        $category = new BugReportCategory(
            'general',
            '<b>General</b>',
            '<img src=x onerror=alert(1)>',
            BugReportSeverity::Medium,
            10,
            true,
        );

        $html = BugReportFormHtml::page(
            [$category],
            $category,
            'csrf-token',
            new BasePath('/community'),
            null,
            false,
            '/threads/' . str_repeat('a', 32),
        );

        self::assertStringContainsString('/community/bugs/new', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"', $html);
        self::assertStringContainsString('name="reproduction_steps"', $html);
        self::assertStringContainsString('name="expected_result"', $html);
        self::assertStringContainsString('name="actual_result"', $html);
        self::assertStringContainsString('name="attachments[]" multiple', $html);
        self::assertStringContainsString('enctype="multipart/form-data"', $html);
        self::assertStringContainsString('name="source_path"', $html);
        self::assertStringNotContainsString('<b>General</b>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringContainsString('&lt;b&gt;General&lt;/b&gt;', $html);
    }
}
