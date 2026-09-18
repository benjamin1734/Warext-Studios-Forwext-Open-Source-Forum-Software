<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Support;

use Forwext\App\Web\Support\SupportTicketFormHtml;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Support\Intake\SupportFieldDefinition;
use Forwext\Core\Support\Intake\SupportFieldType;
use Forwext\Core\Support\Ticket\SupportCategory;
use Forwext\Core\Support\Ticket\SupportTicketPriority;
use PHPUnit\Framework\TestCase;

final class SupportTicketFormHtmlTest extends TestCase
{
    public function testDynamicLabelsHelpAndCategoryTextAreEscaped(): void
    {
        $category = new SupportCategory(
            'general',
            '<b>General</b>',
            '<img src=x onerror=alert(1)>',
            SupportTicketPriority::Normal,
            60,
            120,
            10,
            true,
        );
        $field = new SupportFieldDefinition(
            'general',
            'details',
            '<script>alert(1)</script>',
            SupportFieldType::Text,
            true,
            [],
            100,
            '<svg onload=alert(1)>',
            10,
            true,
        );

        $html = SupportTicketFormHtml::page(
            [$category],
            $category,
            [$field],
            'csrf-token',
            new BasePath('/community'),
        );

        self::assertStringContainsString('/community/support/new', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"', $html);
        self::assertStringContainsString('name="field_details"', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        self::assertStringNotContainsString('<svg onload=alert(1)>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;General&lt;/b&gt;', $html);
    }
}
