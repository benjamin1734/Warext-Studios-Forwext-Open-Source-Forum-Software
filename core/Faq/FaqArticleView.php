<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

final readonly class FaqArticleView
{
    public function __construct(
        public FaqArticle $article,
        public FaqCategory $category,
        public FaqHelpfulSummary $helpful,
    ) {
    }
}
