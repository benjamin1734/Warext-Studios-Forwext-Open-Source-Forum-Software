<?php

declare(strict_types=1);

namespace Forwext\Core\Faq;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use RuntimeException;
use ValueError;

final readonly class DatabaseFaqRepository implements FaqRepository
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function categories(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT category_key,label,description,language,visibility,sort_order,active '
            . 'FROM forwext_faq_categories ORDER BY language,sort_order,category_key',
        ));
        return array_map($this->hydrateCategory(...), $rows);
    }

    public function category(string $key): ?FaqCategory
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT category_key,label,description,language,visibility,sort_order,active '
            . 'FROM forwext_faq_categories WHERE category_key=:category_key LIMIT 1',
            ['category_key'=>$key],
        ));
        return $row === null ? null : $this->hydrateCategory($row);
    }

    public function saveCategory(FaqCategory $category): void
    {
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_faq_categories '
            . '(category_key,label,description,language,visibility,sort_order,active,created_at_utc,updated_at_utc) '
            . 'VALUES (:category_key,:label,:description,:language,:visibility,:sort_order,:active,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE label=VALUES(label),description=VALUES(description),language=VALUES(language),'
            . 'visibility=VALUES(visibility),sort_order=VALUES(sort_order),active=VALUES(active),updated_at_utc=VALUES(updated_at_utc)',
            [
                'category_key'=>$category->key,
                'label'=>$category->label,
                'description'=>$category->description,
                'language'=>$category->language,
                'visibility'=>$category->visibility->value,
                'sort_order'=>$category->sortOrder,
                'active'=>$category->active,
            ],
        ));
        if ($affected > 2) {
            throw new FaqOperationException('FAQ category mutation affected an invalid row count.');
        }
    }

    public function articles(): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT article_id,category_key,slug,question,answer,visibility,language,sort_order,seo_title,seo_description,'
            . 'active,created_at_utc,updated_at_utc FROM forwext_faq_articles '
            . 'ORDER BY language,category_key,sort_order,article_id',
        ));
        return array_map($this->hydrateArticle(...), $rows);
    }

    public function articlesByCategory(string $categoryKey): array
    {
        $rows = $this->database->fetchAll(new CompiledQuery(
            'SELECT article_id,category_key,slug,question,answer,visibility,language,sort_order,seo_title,seo_description,'
            . 'active,created_at_utc,updated_at_utc FROM forwext_faq_articles '
            . 'WHERE category_key=:category_key ORDER BY sort_order,article_id',
            ['category_key'=>$categoryKey],
        ));
        return array_map($this->hydrateArticle(...), $rows);
    }

    public function article(EntityId $articleId): ?FaqArticle
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT article_id,category_key,slug,question,answer,visibility,language,sort_order,seo_title,seo_description,'
            . 'active,created_at_utc,updated_at_utc FROM forwext_faq_articles WHERE article_id=:article_id LIMIT 1',
            ['article_id'=>$articleId->value()],
        ));
        return $row === null ? null : $this->hydrateArticle($row);
    }

    public function articleBySlug(string $language, string $slug): ?FaqArticle
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT article_id,category_key,slug,question,answer,visibility,language,sort_order,seo_title,seo_description,'
            . 'active,created_at_utc,updated_at_utc FROM forwext_faq_articles '
            . 'WHERE language=:language AND slug=:slug LIMIT 1',
            ['language'=>$language,'slug'=>$slug],
        ));
        return $row === null ? null : $this->hydrateArticle($row);
    }

    public function saveArticle(FaqArticle $article): void
    {
        $persist = function () use ($article): void {
            $affected = $this->database->execute(new CompiledQuery(
                'INSERT INTO forwext_faq_articles '
                . '(article_id,category_key,slug,question,answer,visibility,language,sort_order,seo_title,seo_description,'
                . 'active,created_at_utc,updated_at_utc) '
                . 'VALUES (:article_id,:category_key,:slug,:question,:answer,:visibility,:language,:sort_order,:seo_title,'
                . ':seo_description,:active,:created_at,:updated_at) '
                . 'ON DUPLICATE KEY UPDATE category_key=VALUES(category_key),slug=VALUES(slug),question=VALUES(question),'
                . 'answer=VALUES(answer),visibility=VALUES(visibility),language=VALUES(language),sort_order=VALUES(sort_order),'
                . 'seo_title=VALUES(seo_title),seo_description=VALUES(seo_description),active=VALUES(active),'
                . 'updated_at_utc=VALUES(updated_at_utc)',
                [
                    'article_id'=>$article->articleId->value(),
                    'category_key'=>$article->categoryKey,
                    'slug'=>$article->slug,
                    'question'=>$article->question,
                    'answer'=>$article->answer,
                    'visibility'=>$article->visibility->value,
                    'language'=>$article->language,
                    'sort_order'=>$article->sortOrder,
                    'seo_title'=>$article->seoTitle,
                    'seo_description'=>$article->seoDescription,
                    'active'=>$article->active,
                    'created_at'=>self::format($article->createdAt),
                    'updated_at'=>self::format($article->updatedAt),
                ],
            ));
            if ($affected > 2) {
                throw new FaqOperationException('FAQ article mutation affected an invalid row count.');
            }

            $this->database->execute(new CompiledQuery(
                'DELETE FROM forwext_faq_article_tags WHERE article_id=:article_id',
                ['article_id'=>$article->articleId->value()],
            ));
            foreach ($article->tags as $tag) {
                if ($this->database->execute(new CompiledQuery(
                    'INSERT INTO forwext_faq_article_tags (article_id,tag_key) VALUES (:article_id,:tag_key)',
                    ['article_id'=>$article->articleId->value(),'tag_key'=>$tag],
                )) !== 1) {
                    throw new FaqOperationException('FAQ tag insertion failed.');
                }
            }
        };

        if ($this->database->inTransaction()) {
            $persist();
        } else {
            $this->database->transaction(static fn () => $persist());
        }
    }

    public function recordHelpful(EntityId $articleId, EntityId $userId, bool $helpful): void
    {
        UserId::assert($userId);
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_faq_helpful_votes (article_id,user_id,helpful,created_at_utc,updated_at_utc) '
            . 'VALUES (:article_id,:user_id,:helpful,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE helpful=VALUES(helpful),updated_at_utc=VALUES(updated_at_utc)',
            ['article_id'=>$articleId->value(),'user_id'=>$userId->value(),'helpful'=>$helpful],
        ));
        if ($affected < 0 || $affected > 2) {
            throw new FaqOperationException('FAQ helpful vote mutation affected an invalid row count.');
        }
    }

    public function helpfulSummary(EntityId $articleId): FaqHelpfulSummary
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT COALESCE(SUM(helpful=1),0) AS helpful,COALESCE(SUM(helpful=0),0) AS not_helpful '
            . 'FROM forwext_faq_helpful_votes WHERE article_id=:article_id',
            ['article_id'=>$articleId->value()],
        ));
        return new FaqHelpfulSummary(
            (int) ($row['helpful'] ?? 0),
            (int) ($row['not_helpful'] ?? 0),
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateCategory(array $row): FaqCategory
    {
        try {
            $visibility = FaqVisibility::from((string) $row['visibility']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored FAQ category visibility is invalid.', previous:$exception);
        }
        return new FaqCategory(
            (string) $row['category_key'],
            (string) $row['label'],
            (string) ($row['description'] ?? ''),
            (string) $row['language'],
            $visibility,
            (int) $row['sort_order'],
            (bool) $row['active'],
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrateArticle(array $row): FaqArticle
    {
        try {
            $visibility = FaqVisibility::from((string) $row['visibility']);
        } catch (ValueError $exception) {
            throw new RuntimeException('Stored FAQ article visibility is invalid.', previous:$exception);
        }
        $tags = [];
        $tagRows = $this->database->fetchAll(new CompiledQuery(
            'SELECT tag_key FROM forwext_faq_article_tags WHERE article_id=:article_id ORDER BY tag_key',
            ['article_id'=>(string) $row['article_id']],
        ));
        foreach ($tagRows as $tagRow) {
            $tag = $tagRow['tag_key'] ?? null;
            if (!is_string($tag)) {
                throw new RuntimeException('Stored FAQ tag is invalid.');
            }
            $tags[] = $tag;
        }

        return new FaqArticle(
            EntityId::fromString((string) $row['article_id']),
            (string) $row['category_key'],
            (string) $row['slug'],
            (string) $row['question'],
            (string) $row['answer'],
            $tags,
            $visibility,
            (string) $row['language'],
            (int) $row['sort_order'],
            $row['seo_title'] === null ? null : (string) $row['seo_title'],
            $row['seo_description'] === null ? null : (string) $row['seo_description'],
            (bool) $row['active'],
            self::parse((string) $row['created_at_utc']),
            self::parse((string) $row['updated_at_utc']),
        );
    }

    private static function format(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $value): DateTimeImmutable
    {
        foreach (['!Y-m-d H:i:s.u','!Y-m-d H:i:s'] as $format) {
            $time = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            if ($time instanceof DateTimeImmutable) {
                return $time;
            }
        }
        throw new RuntimeException('Stored FAQ timestamp is invalid.');
    }
}
