<?php

declare(strict_types=1);

namespace Forwext\Core\Reward;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class RewardSourceOption
{
    public function __construct(public EntityId $sourceDefinitionId,public string $label)
    {
        if(preg_match('/^[a-f0-9]{32}$/D',$this->sourceDefinitionId->value())!==1
            ||trim($this->label)===''||strlen($this->label)>200||preg_match('//u',$this->label)!==1){
            throw new InvalidArgumentException('Reward source option is invalid.');
        }
    }
}
