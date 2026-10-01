<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ReportAndDisciplineAccountWebSurfaceTest extends TestCase
{
    public function testUserFacingReportAndDisciplinePagesUseSharedSurfaces(): void
    {
        $root=dirname(__DIR__,4);
        $report=(string)file_get_contents($root.'/app/Web/Report/ReportHtml.php');
        $discipline=(string)file_get_contents($root.'/app/Web/Moderation/DisciplineAccountHtml.php');
        $css=(string)file_get_contents($root.'/public/assets/site-components.css')
            .(string)file_get_contents($root.'/public/assets/site-pages.css');

        self::assertStringContainsString('report-form-page discovery-page',$report);
        self::assertStringContainsString('report-history-page discovery-page',$report);
        self::assertStringContainsString('class="report-history-row"',$report);
        self::assertStringContainsString('discipline-account-page discovery-page',$discipline);
        self::assertStringContainsString('class="discipline-account-row"',$discipline);
        self::assertStringContainsString('.report-history-panel',$css);
        self::assertStringContainsString('.discipline-account-panel',$css);
    }
}
