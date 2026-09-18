<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Report;

use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final class ReportableContentRegistry
{
    /** @var array<string, ReportableContentResolver> */
    private array $resolvers = [];

    /** @param list<ReportableContentResolver> $resolvers */
    public function __construct(array $resolvers = [])
    {
        foreach ($resolvers as $resolver) {
            $this->register($resolver);
        }
    }

    public function register(ReportableContentResolver $resolver): void
    {
        $type = $resolver->targetType();
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $type) !== 1) {
            throw new InvalidArgumentException('Reportable resolver type is invalid.');
        }
        if (isset($this->resolvers[$type])) {
            throw new InvalidArgumentException(sprintf('Reportable resolver "%s" is already registered.', $type));
        }
        $this->resolvers[$type] = $resolver;
    }

    public function resolve(string $targetType, EntityId $viewerUserId, EntityId $targetId): ?ReportableContent
    {
        $resolver = $this->resolvers[$targetType] ?? null;
        if ($resolver === null) {
            return null;
        }

        $content = $resolver->resolve($viewerUserId, $targetId);
        if ($content !== null && $content->targetType !== $targetType) {
            throw new InvalidArgumentException('Reportable resolver returned a mismatched target type.');
        }
        return $content;
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        $types = array_keys($this->resolvers);
        sort($types, SORT_STRING);
        return $types;
    }
}
