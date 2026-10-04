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
        self::assertStringContainsString('surface-head faq-article-head', $html);
        self::assertStringContainsString('faq-article-grid', $html);
        self::assertStringContainsString('surface-panel faq-answer', $html);
        self::assertStringContainsString('faq-article-tags', $html);
        self::assertStringContainsString('surface-panel faq-feedback-panel', $html);
        self::assertStringContainsString('faq-feedback-actions', $html);
        $manage=(string)file_get_contents(dirname(__DIR__,5).'/app/Web/Faq/FaqHtml.php');
        self::assertStringContainsString('module-manage-page discovery-page',$manage);
        self::assertStringContainsString('module-manage-head-actions',$manage);
        self::assertStringContainsString('class="faq-manage-row"',$manage);
    }


    public function testFaqIndexUsesDenseFilterAndCategorySurfaces(): void
    {
        $now = new DateTimeImmutable('2026-09-18 18:00:00', new DateTimeZone('UTC'));
        $category = new FaqCategory(
            'general',
            'Genel',
            'Genel yardım makaleleri',
            'tr',
            FaqVisibility::Public,
            10,
            true,
        );
        $article = new FaqArticle(
            EntityId::fromString(str_repeat('b',32)),
            'general',
            'ornek-soru',
            'Örnek soru?',
            'Örnek cevap.',
            ['help','forum'],
            FaqVisibility::Public,
            'tr',
            10,
            null,
            null,
            true,
            $now,
            $now,
        );

        $html = FaqHtml::index(
            [$category],
            ['general' => [$article]],
            new BasePath('/community'),
            'tr',
            true,
        );

        self::assertStringContainsString('surface-panel faq-overview', $html);
        self::assertStringContainsString('faq-overview-stats', $html);
        self::assertStringContainsString('faq-tab is-active', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('faq-category-head', $html);
        self::assertStringContainsString('1 soru', $html);
        self::assertStringContainsString('faq-row-tags', $html);
        self::assertStringContainsString('#help', $html);
        self::assertStringContainsString('/community/faq/tr/ornek-soru', $html);
    }
}
