<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Profile;

use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class ProfileHtmlBugEntryTest extends TestCase
{
    public function testAuthenticatedPagesExposeIconOnlyBugEntryAndGuestPagesDoNot(): void
    {
        $basePath = new BasePath('/community');

        $authenticated = ProfileHtml::page(
            'Test',
            '<p>Body</p>',
            $basePath,
            authenticated: true,
        );

        self::assertStringContainsString('data-bug-report-entry', $authenticated);
        self::assertStringContainsString('href="/community/bugs/new"', $authenticated);
        self::assertStringContainsString('aria-label="Hata bildir"', $authenticated);
        self::assertStringContainsString('<svg', $authenticated);
        self::assertStringNotContainsString('>Hata bildir</a>', $authenticated);

        $guest = ProfileHtml::page('Test', '<p>Body</p>', $basePath, authenticated: false);
        self::assertStringNotContainsString('data-bug-report-entry', $guest);
    }
}
