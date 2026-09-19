<?php

declare(strict_types=1);

namespace Forwext\Core\Marketplace;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class MarketplaceCustomFieldDefinition
{
    /** @var list<string> */
    public array $options;

    /** @param list<string> $options */
    public function __construct(
        public EntityId $fieldId,
        public EntityId $categoryId,
        public string $key,
        public string $label,
        public MarketplaceCustomFieldType $type,
        public bool $required,
        array $options,
        public int $sortOrder,
        public bool $active=true,
    ){
        if(preg_match('/^[a-f0-9]{32}$/D',$this->fieldId->value())!==1
            ||preg_match('/^[a-f0-9]{32}$/D',$this->categoryId->value())!==1
            ||preg_match('/^[a-z][a-z0-9_.-]{1,63}$/D',$this->key)!==1
            ||trim($this->label)===''||strlen($this->label)>120||preg_match('//u',$this->label)!==1
            ||$this->sortOrder<0||$this->sortOrder>1000
        ){
            throw new InvalidArgumentException('Marketplace custom field definition is invalid.');
        }
        $normalized=[];
        foreach($options as $option){
            if(!is_string($option))throw new InvalidArgumentException('Marketplace custom field options are invalid.');
            $option=trim($option);
            if($option===''||strlen($option)>120||preg_match('//u',$option)!==1)throw new InvalidArgumentException('Marketplace custom field option is invalid.');
            $normalized[$option]=true;
        }
        if(count($normalized)>100)throw new InvalidArgumentException('Marketplace custom field option limit exceeded.');
        if($this->type===MarketplaceCustomFieldType::Select&&$normalized===[]){
            throw new InvalidArgumentException('Marketplace select field requires options.');
        }
        if($this->type!==MarketplaceCustomFieldType::Select&&$normalized!==[]){
            throw new InvalidArgumentException('Only marketplace select fields may define options.');
        }
        $this->options=array_keys($normalized);
    }

    public static function generateId():EntityId{return EntityId::fromString(bin2hex(random_bytes(16)));}

    public function normalize(mixed $value):string|int|bool
    {
        return match($this->type){
            MarketplaceCustomFieldType::Text=>$this->text($value),
            MarketplaceCustomFieldType::Integer=>$this->integer($value),
            MarketplaceCustomFieldType::Boolean=>$this->boolean($value),
            MarketplaceCustomFieldType::Select=>$this->select($value),
        };
    }

    private function text(mixed $value):string
    {
        if(!is_string($value)||strlen($value)>5000||preg_match('//u',$value)!==1){
            throw new InvalidArgumentException('Marketplace text custom value is invalid.');
        }
        return trim($value);
    }

    private function integer(mixed $value):int
    {
        if(!is_int($value))throw new InvalidArgumentException('Marketplace integer custom value is invalid.');
        return $value;
    }

    private function boolean(mixed $value):bool
    {
        if(!is_bool($value))throw new InvalidArgumentException('Marketplace boolean custom value is invalid.');
        return $value;
    }

    private function select(mixed $value):string
    {
        if(!is_string($value)||!in_array($value,$this->options,true)){
            throw new InvalidArgumentException('Marketplace select custom value is invalid.');
        }
        return $value;
    }
}
