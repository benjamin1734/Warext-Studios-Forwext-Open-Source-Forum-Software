<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class RewardTargetOption
{
    public function __construct(public EntityId $targetId,public string $label)
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$this->targetId->value())!==1
            ||trim($this->label)===''||strlen($this->label)>120||preg_match('//u',$this->label)!==1){
            throw new InvalidArgumentException('Reward target option is invalid.');
        }
    }
}
