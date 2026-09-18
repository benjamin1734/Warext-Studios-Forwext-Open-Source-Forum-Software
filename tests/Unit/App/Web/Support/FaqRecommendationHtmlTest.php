<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web\Support;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Support\FaqRecommendationHtml;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Faq\FaqArticle;
use Forwext\Core\Faq\FaqArticleView;
use Forwext\Core\Faq\FaqCategory;
use Forwext\Core\Faq\FaqHelpfulSummary;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Faq\SupportBridge\FaqSupportRecommendation;
use Forwext\Core\Routing\BasePath;
use PHPUnit\Framework\TestCase;

final class FaqRecommendationHtmlTest extends TestCase
{
    public function testRecommendationContentIsEscapedAndUsesCanonicalFaqPath():void
    {
        $now=new DateTimeImmutable('2026-09-18 18:30:00',new DateTimeZone('UTC'));
        $category=new FaqCategory('general','<b>General</b>','','tr',FaqVisibility::Public,10,true);
        $article=new FaqArticle(
            EntityId::fromString(str_repeat('a',32)),
            'general',
            'guvenli-cozum',
            '<script>alert(1)</script>',
            'Answer',
            [],
            FaqVisibility::Public,
            'tr',
            10,
            null,
            null,
            true,
            $now,
            $now,
        );
        $html=FaqRecommendationHtml::section([
            new FaqSupportRecommendation(
                new FaqArticleView($article,$category,new FaqHelpfulSummary(0,0)),
                42,
                ['question'],
            ),
        ],new BasePath('/community'));

        self::assertStringNotContainsString('<script>alert(1)</script>',$html);
        self::assertStringNotContainsString('<b>General</b>',$html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$html);
        self::assertStringContainsString('/community/faq/tr/guvenli-cozum',$html);
    }
}
