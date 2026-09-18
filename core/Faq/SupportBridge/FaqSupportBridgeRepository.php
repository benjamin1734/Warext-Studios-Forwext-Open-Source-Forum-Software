<?php
declare(strict_types=1);
namespace Forwext\Core\Faq\SupportBridge;
use Forwext\Core\Domain\Entity\EntityId;
interface FaqSupportBridgeRepository {
    /** @param list<string> $tokens @return list<EntityId> */
    public function candidateArticleIds(string $supportCategoryKey,array $tokens,int $limit=100):array;
    public function create(FaqSupportDraftSuggestion $draft): void;
    public function find(EntityId $draftId): ?FaqSupportDraftSuggestion;
    /** @return list<FaqSupportDraftSuggestion> */
    public function pending(int $limit=100): array;
    public function updateStatus(EntityId $draftId,FaqSupportDraftStatus $status): void;
}
