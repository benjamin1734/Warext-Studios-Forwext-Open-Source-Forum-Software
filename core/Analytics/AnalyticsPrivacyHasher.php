<?php

declare(strict_types=1);

namespace Forwext\Core\Analytics;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretKey;

final readonly class AnalyticsPrivacyHasher
{
    public function __construct(private SecretKey $key){}

    public function actor(EntityId $userId):string
    {
        return $this->hash('actor',$userId->value());
    }

    public function session(string $sessionId):string
    {
        return $this->hash('session',$sessionId);
    }

    public function subject(string $type,EntityId $id):string
    {
        return $this->hash('subject',$type.':'.$id->value());
    }

    private function hash(string $domain,string $value):string
    {
        return hash_hmac('sha256',$domain."\0".$value,$this->key->bytesForCrypto());
    }
}
