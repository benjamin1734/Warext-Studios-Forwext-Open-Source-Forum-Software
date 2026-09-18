<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Search\Lifecycle\SearchIndexChangeStore;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use ValueError;

final readonly class FaqService
{
    public const VIEW_PERMISSION = 'faq.view';
    public const MANAGE_PERMISSION = 'faq.manage';
    public const SEARCH_TYPE = 'faq.article';

    public function __construct(
        private TransactionalQueryExecutor $database,
        private FaqRepository $faq,
        private PermissionAuthorizer $authorizer,
        private SearchIndexChangeStore $searchChanges,
    ) {
    }

    /** @return list<FaqCategory> */
    public function categories(?EntityId $actor, ?string $language = null): array
    {
        if ($language !== null) {
            FaqCategory::assertLanguage($language);
        }
        return array_values(array_filter(
            $this->faq->categories(),
            fn (FaqCategory $category): bool =>
                $category->active
                && ($language === null || strcasecmp($category->language, $language) === 0)
                && $this->canSee($category->visibility, $actor),
        ));
    }

    /** @return list<FaqArticle> */
    public function articlesIn(string $categoryKey, ?EntityId $actor): array
    {
        $category = $this->faq->category($categoryKey)
            ?? throw new FaqOperationException('FAQ category was not found.');
        if (!$category->active || !$this->canSee($category->visibility, $actor)) {
            throw new FaqOperationException('FAQ category is unavailable.');
        }

        return array_values(array_filter(
            $this->faq->articlesByCategory($categoryKey),
            fn (FaqArticle $article): bool =>
                $article->active
                && $this->canSee($article->effectiveVisibility($category), $actor),
        ));
    }

    public function article(EntityId $articleId, ?EntityId $actor): FaqArticleView
    {
        $article = $this->faq->article($articleId)
            ?? throw new FaqOperationException('FAQ article was not found.');
        return $this->visibleArticle($article, $actor);
    }

    public function articleBySlug(string $language, string $slug, ?EntityId $actor): FaqArticleView
    {
        FaqCategory::assertLanguage($language);
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,159}$/D', $slug) !== 1) {
            throw new InvalidArgumentException('FAQ slug is invalid.');
        }
        $article = $this->faq->articleBySlug($language, $slug)
            ?? throw new FaqOperationException('FAQ article was not found.');
        return $this->visibleArticle($article, $actor);
    }

    public function saveCategory(EntityId $actor, FaqCategory $category): void
    {
        $this->requireManage($actor);
        $this->database->transaction(function () use ($category): void {
            $this->faq->saveCategory($category);
            foreach ($this->faq->articlesByCategory($category->key) as $article) {
                $this->searchChanges->record(self::SEARCH_TYPE, $article->articleId->value());
            }
        });
    }

    public function saveArticle(EntityId $actor, FaqArticle $article): void
    {
        $this->requireManage($actor);
        $category = $this->faq->category($article->categoryKey)
            ?? throw new InvalidArgumentException('FAQ article category does not exist.');
        if (strcasecmp($category->language, $article->language) !== 0) {
            throw new InvalidArgumentException('FAQ article language must match its category language.');
        }

        $this->database->transaction(function () use ($article): void {
            $this->faq->saveArticle($article);
            $this->searchChanges->record(self::SEARCH_TYPE, $article->articleId->value());
        });
    }

    public function voteHelpful(EntityId $actor, EntityId $articleId, bool $helpful): FaqHelpfulSummary
    {
        $this->article($articleId, $actor);
        $this->faq->recordHelpful($articleId, $actor, $helpful);
        return $this->faq->helpfulSummary($articleId);
    }

    /**
     * @return array{categories:list<FaqCategory>,articles:list<FaqArticle>,helpful:array<string,FaqHelpfulSummary>}
     */
    public function managementSnapshot(EntityId $actor): array
    {
        $this->requireManage($actor);
        $articles = $this->faq->articles();
        $helpful = [];
        foreach ($articles as $article) {
            $helpful[$article->articleId->value()] = $this->faq->helpfulSummary($article->articleId);
        }
        return ['categories'=>$this->faq->categories(),'articles'=>$articles,'helpful'=>$helpful];
    }

    public function managementArticle(EntityId $actor, EntityId $articleId): ?FaqArticle
    {
        $this->requireManage($actor);
        return $this->faq->article($articleId);
    }

    public function export(EntityId $actor): string
    {
        $this->requireManage($actor);
        $categories = array_map(
            static fn (FaqCategory $category): array => [
                'key'=>$category->key,
                'label'=>$category->label,
                'description'=>$category->description,
                'language'=>$category->language,
                'visibility'=>$category->visibility->value,
                'sort_order'=>$category->sortOrder,
                'active'=>$category->active,
            ],
            $this->faq->categories(),
        );
        $articles = array_map(
            static fn (FaqArticle $article): array => [
                'id'=>$article->articleId->value(),
                'category'=>$article->categoryKey,
                'slug'=>$article->slug,
                'question'=>$article->question,
                'answer'=>$article->answer,
                'tags'=>$article->tags,
                'visibility'=>$article->visibility->value,
                'language'=>$article->language,
                'sort_order'=>$article->sortOrder,
                'seo_title'=>$article->seoTitle,
                'seo_description'=>$article->seoDescription,
                'active'=>$article->active,
                'created_at'=>$article->createdAt->format(DATE_ATOM),
                'updated_at'=>$article->updatedAt->format(DATE_ATOM),
            ],
            $this->faq->articles(),
        );

        try {
            return json_encode(
                ['format'=>'forwext-faq','version'=>1,'categories'=>$categories,'articles'=>$articles],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('FAQ export could not be encoded.', previous:$exception);
        }
    }

    public function import(EntityId $actor, string $json): int
    {
        $this->requireManage($actor);
        if ($json === '' || strlen($json) > 5_000_000) {
            throw new InvalidArgumentException('FAQ import payload size is invalid.');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('FAQ import JSON is invalid.', previous:$exception);
        }
        if (!is_array($payload)
            || ($payload['format'] ?? null) !== 'forwext-faq'
            || ($payload['version'] ?? null) !== 1
            || !is_array($payload['categories'] ?? null)
            || !is_array($payload['articles'] ?? null)
            || count($payload['categories']) > 500
            || count($payload['articles']) > 10000
        ) {
            throw new InvalidArgumentException('FAQ import schema is invalid or exceeds supported limits.');
        }

        $categories = [];
        foreach ($payload['categories'] as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('FAQ import category row is invalid.');
            }
            try {
                $visibility = FaqVisibility::from(self::string($row, 'visibility', 16));
            } catch (ValueError $exception) {
                throw new InvalidArgumentException('FAQ import category visibility is invalid.', previous:$exception);
            }
            $category = new FaqCategory(
                self::string($row, 'key', 64),
                self::string($row, 'label', 120),
                self::optionalString($row, 'description', 500) ?? '',
                self::string($row, 'language', 32),
                $visibility,
                self::integer($row, 'sort_order', 0, 65535),
                self::boolean($row, 'active'),
            );
            $categories[$category->key] = $category;
        }

        $articles = [];
        foreach ($payload['articles'] as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('FAQ import article row is invalid.');
            }
            try {
                $visibility = FaqVisibility::from(self::string($row, 'visibility', 16));
            } catch (ValueError $exception) {
                throw new InvalidArgumentException('FAQ import article visibility is invalid.', previous:$exception);
            }
            $categoryKey = self::string($row, 'category', 64);
            $category = $categories[$categoryKey] ?? $this->faq->category($categoryKey);
            if ($category === null) {
                throw new InvalidArgumentException('FAQ import article references an unknown category.');
            }
            $language = self::string($row, 'language', 32);
            if (strcasecmp($category->language, $language) !== 0) {
                throw new InvalidArgumentException('FAQ import article language does not match its category.');
            }
            $tags = $row['tags'] ?? null;
            if (!is_array($tags) || count($tags) > 32) {
                throw new InvalidArgumentException('FAQ import article tags are invalid.');
            }

            $article = new FaqArticle(
                EntityId::fromString(self::string($row, 'id', 32)),
                $categoryKey,
                self::string($row, 'slug', 160),
                self::string($row, 'question', 300),
                self::string($row, 'answer', 50000),
                array_values($tags),
                $visibility,
                $language,
                self::integer($row, 'sort_order', 0, 65535),
                self::optionalString($row, 'seo_title', 200),
                self::optionalString($row, 'seo_description', 320),
                self::boolean($row, 'active'),
                self::date(self::string($row, 'created_at', 64)),
                self::date(self::string($row, 'updated_at', 64)),
            );
            $articles[] = $article;
        }

        $this->database->transaction(function () use ($categories, $articles): void {
            foreach ($categories as $category) {
                $this->faq->saveCategory($category);
                foreach ($this->faq->articlesByCategory($category->key) as $existingArticle) {
                    $this->searchChanges->record(self::SEARCH_TYPE, $existingArticle->articleId->value());
                }
            }
            foreach ($articles as $article) {
                $this->faq->saveArticle($article);
                $this->searchChanges->record(self::SEARCH_TYPE, $article->articleId->value());
            }
        });

        return count($categories) + count($articles);
    }

    private function visibleArticle(FaqArticle $article, ?EntityId $actor): FaqArticleView
    {
        $category = $this->faq->category($article->categoryKey)
            ?? throw new FaqOperationException('FAQ article category was not found.');
        if (!$category->active
            || !$article->active
            || !$this->canSee($article->effectiveVisibility($category), $actor)
        ) {
            throw new FaqOperationException('FAQ article is unavailable.');
        }
        return new FaqArticleView($article, $category, $this->faq->helpfulSummary($article->articleId));
    }

    private function canSee(FaqVisibility $visibility, ?EntityId $actor): bool
    {
        if ($visibility === FaqVisibility::Public) {
            return true;
        }
        if ($actor === null) {
            return false;
        }
        if ($visibility === FaqVisibility::Staff) {
            return $this->authorizer->allows($actor, PermissionKey::fromString(self::MANAGE_PERMISSION));
        }
        return $this->authorizer->allows($actor, PermissionKey::fromString(self::VIEW_PERMISSION))
            || $this->authorizer->allows($actor, PermissionKey::fromString(self::MANAGE_PERMISSION));
    }

    private function requireManage(EntityId $actor): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString(self::MANAGE_PERMISSION));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    /** @param array<string,mixed> $row */
    private static function string(array $row, string $key, int $max): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $max) {
            throw new InvalidArgumentException('FAQ import string field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function optionalString(array $row, string $key, int $max): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max) {
            throw new InvalidArgumentException('FAQ import optional string field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function integer(array $row, string $key, int $min, int $max): int
    {
        $value = $row[$key] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('FAQ import integer field is invalid: ' . $key);
        }
        return $value;
    }

    /** @param array<string,mixed> $row */
    private static function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException('FAQ import boolean field is invalid: ' . $key);
        }
        return $value;
    }

    private static function date(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('FAQ import timestamp is invalid.', previous:$exception);
        }
    }
}
