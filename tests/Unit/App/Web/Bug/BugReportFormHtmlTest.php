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
    public function testFormEscapesCategoryAndSourceAndContainsRequiredFields(): void
    {
        $html=BugReportFormHtml::page(
            [new BugReportCategory('general','<script>alert(1)</script>','',BugReportSeverity::Medium,10,true)],
            'csrf-token',
            new BasePath('/community'),
            '/threads/<unsafe>',
        );

        self::assertStringContainsString('/community/bugs/report',$html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"',$html);
        self::assertStringContainsString('name="reproduction_steps"',$html);
        self::assertStringContainsString('name="expected_result"',$html);
        self::assertStringContainsString('name="actual_result"',$html);
        self::assertStringContainsString('name="attachments[]"',$html);
        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        self::assertStringContainsString('/threads/&lt;unsafe&gt;',$html);
    }
}
