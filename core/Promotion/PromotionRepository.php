<?php

declare(strict_types=1);

namespace Forwext\Core\Promotion;

use Forwext\Core\Domain\Entity\EntityId;

interface PromotionRepository
{
    public function find(EntityId $promotionId):?PromotionDefinition;

    /** @return list<PromotionDefinition> */
    public function definitions(bool $activeOnly=false,int $limit=500):array;

    public function save(PromotionDefinition $definition):void;

    public function evaluationCursor():?EntityId;

    public function setEvaluationCursor(?EntityId $userId):void;

    /** @return list<EntityId> */
    public function evaluationCandidates(?EntityId $afterUserId,int $limit):array;
}
