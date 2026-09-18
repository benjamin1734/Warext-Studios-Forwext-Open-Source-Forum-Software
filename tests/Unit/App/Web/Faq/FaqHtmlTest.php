<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Faq;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Faq\FaqHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqArticle;
use Forwext\Core\Faq\FaqArticleView;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\FaqHelpfulSummary;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class FaqHtmlTest extends TestCase
{
    public function testFaqArticleEscapesQuestionAnswerTagsAndCategory(): void
    {
        $now = new DateTimeImmutable('2026-09-18 18:00:00', new DateTimeZone('UTC'));
        $category = new FaqCategory(
            'general',
            '<b>General</b>',
            '',
            'tr',
            FaqVisibility::Public,
            10,
            true,
        );
        $article = new FaqArticle(
            EntityId::fromString(str_repeat('a',32)),
            'general',
            'guvenli-soru',
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(2)>',
            ['help'],
            FaqVisibility::Public,
            'tr',
            10,
            null,
            null,
            true,
            $now,
            $now,
        );

        $html = FaqHtml::article(
            new FaqArticleView($article,$category,new FaqHelpfulSummary(4,1)),
            new BasePath('/community'),
            'csrf-token',
            true,
        );

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<img src=x onerror=alert(2)>', $html);
        self::assertStringNotContainsString('<b>General</b>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('name="_csrf" value="csrf-token"', $html);
        self::assertStringContainsString('/community/faq/tr/guvenli-soru', $html);
    }
}
