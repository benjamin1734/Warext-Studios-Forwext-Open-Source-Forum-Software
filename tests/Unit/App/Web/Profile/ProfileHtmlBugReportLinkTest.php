<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class ProfileHtmlBugReportLinkTest extends TestCase
{
    public function testAuthenticatedPagesExposeGlobalBugReportAction(): void
    {
        $html=ProfileHtml::page('Test','<p>Body</p>',new BasePath('/community'),authenticated:true);

        self::assertStringContainsString('data-bug-report-link',$html);
        self::assertStringContainsString('/community/bugs/report',$html);
        self::assertStringContainsString('/community/assets/bug-report-link.js',$html);
    }

    public function testAnonymousPagesDoNotExposeBugReportAction(): void
    {
        $html=ProfileHtml::page('Test','<p>Body</p>',new BasePath('/community'),authenticated:false);

        self::assertStringNotContainsString('data-bug-report-link',$html);
    }
}
