<?php

declare(strict_types=1);

namespace Forwext\Core\Support\Intake;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class SupportContextRegistry
{
    /** @var array<string,SupportContextResolver> */
    private array $resolvers = [];

    /** @param list<SupportContextResolver> $resolvers */
    public function __construct(array $resolvers)
    {
        foreach ($resolvers as $resolver) {
            if (!$resolver instanceof SupportContextResolver) {
                throw new InvalidArgumentException('Support context registry contains an invalid resolver.');
            }
            $key = $resolver->type()->value;
            if (isset($this->resolvers[$key])) {
                throw new InvalidArgumentException('Support context resolver is duplicated: ' . $key);
            }
            $this->resolvers[$key] = $resolver;
        }
    }

    public function resolve(
        SupportContextType $type,
        EntityId $actorUserId,
        EntityId $targetId,
    ): SupportContextLink {
        $resolver = $this->resolvers[$type->value] ?? null;
        if ($resolver === null) {
            throw new SupportContextUnavailableException('Support context type is not available.');
        }
        return $resolver->resolve($actorUserId, $targetId);
    }
}
