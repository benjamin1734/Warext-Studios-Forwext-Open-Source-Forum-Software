<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

use Forwext\Core\Domain\Entity\EntityId;

interface FaqRepository
{
    /** @return list<FaqCategory> */
    public function categories(): array;

    public function category(string $key): ?FaqCategory;

    public function saveCategory(FaqCategory $category): void;

    /** @return list<FaqArticle> */
    public function articles(): array;

    /** @return list<FaqArticle> */
    public function articlesByCategory(string $categoryKey): array;

    public function article(EntityId $articleId): ?FaqArticle;

    public function articleBySlug(string $language, string $slug): ?FaqArticle;

    public function saveArticle(FaqArticle $article): void;

    public function recordHelpful(EntityId $articleId, EntityId $userId, bool $helpful): void;

    public function helpfulSummary(EntityId $articleId): FaqHelpfulSummary;
}
