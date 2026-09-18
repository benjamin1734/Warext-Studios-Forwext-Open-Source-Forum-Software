<?php

declare(strict_types=1);

namespace Forwext\Core\Faq\Search;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Faq\FaqVisibility;
use Forwext\Core\Search\Lifecycle\SearchContentPage;
use Forwext\Core\Search\Lifecycle\SearchContentSource;
use Forwext\Core\Search\Lifecycle\SearchIndexScope;
use Forwext\Core\Search\Lifecycle\Source\AbstractDatabaseSearchContentSource;
use Forwext\Core\Search\SearchAttribute;
use Forwext\Core\Search\SearchDocument;
use Forwext\Core\Search\SearchException;
use ValueError;

final readonly class DatabaseFaqSearchContentSource extends AbstractDatabaseSearchContentSource implements SearchContentSource
{
    public function documentType(): string
    {
        return 'faq.article';
    }

    public function document(string $documentId): ?SearchDocument
    {
        $this->validateId($documentId);
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT a.article_id,a.question,a.answer,a.visibility AS article_visibility,a.language,a.updated_at_utc,'
            . 'a.active AS article_active,c.visibility AS category_visibility,c.active AS category_active '
            . 'FROM forwext_faq_articles a INNER JOIN forwext_faq_categories c ON c.category_key=a.category_key '
            . 'WHERE a.article_id=:article_id LIMIT 1',
            ['article_id'=>$documentId],
        ));
        if ($row === null || !(bool) ($row['article_active'] ?? false) || !(bool) ($row['category_active'] ?? false)) {
            return null;
        }
        try {
            $articleVisibility = FaqVisibility::from((string) $row['article_visibility']);
            $categoryVisibility = FaqVisibility::from((string) $row['category_visibility']);
        } catch (ValueError $exception) {
            throw new SearchException('Stored FAQ search visibility is invalid.', previous:$exception);
        }
        $visibility = FaqVisibility::restrictive($articleVisibility, $categoryVisibility);
        $scope = match ($visibility) {
            FaqVisibility::Public => SearchIndexScope::PUBLIC,
            FaqVisibility::Members => FaqSearchAccessScopeProvider::MEMBERS,
            FaqVisibility::Staff => FaqSearchAccessScopeProvider::STAFF,
        };

        $tags = [];
        foreach ($this->database->fetchAll(new CompiledQuery(
            'SELECT tag_key FROM forwext_faq_article_tags WHERE article_id=:article_id ORDER BY tag_key',
            ['article_id'=>$documentId],
        )) as $tagRow) {
            $tag = $tagRow['tag_key'] ?? null;
            if (!is_string($tag)) {
                throw new SearchException('Stored FAQ search tag is invalid.');
            }
            $tags[] = $tag;
        }

        return new SearchDocument(
            $this->documentType(),
            $documentId,
            (string) $row['question'],
            (string) $row['answer'],
            [$scope],
            $this->date((string) $row['updated_at_utc']),
            (string) $row['language'],
            [
                SearchAttribute::TAG=>$tags,
                SearchAttribute::STATE=>['active'],
            ],
        );
    }

    public function scan(?string $afterId, int $limit): SearchContentPage
    {
        return $this->scanIds('forwext_faq_articles', 'article_id', $afterId, $limit);
    }
}
