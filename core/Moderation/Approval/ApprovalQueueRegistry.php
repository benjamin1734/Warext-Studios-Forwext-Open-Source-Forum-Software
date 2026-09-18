<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Approval;

use DateTimeImmutable;
use Forwext\Core\Forum\Moderation\ModerationReasonCode;
use Forwext\Core\Forum\Moderation\ModerationRequestId;
use InvalidArgumentException;

final class ApprovalQueueRegistry
{
    /** @var list<ApprovalQueueProvider> */
    private array $providers = [];

    /** @var array<string, ApprovalQueueProvider> */
    private array $byType = [];

    /** @param list<ApprovalQueueProvider> $providers */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(ApprovalQueueProvider $provider): void
    {
        $types = $provider->sourceTypes();
        if ($types === []) {
            throw new InvalidArgumentException('Approval queue provider must own at least one source type.');
        }
        foreach ($types as $type) {
            if (!is_string($type) || preg_match('/^[a-z][a-z0-9._-]{1,47}$/D', $type) !== 1) {
                throw new InvalidArgumentException('Approval queue provider source type is invalid.');
            }
            if (isset($this->byType[$type])) {
                throw new InvalidArgumentException('Approval queue source type already has an owner.');
            }
        }

        $this->providers[] = $provider;
        foreach ($types as $type) {
            $this->byType[$type] = $provider;
        }
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this->providers as $provider) {
            $providerCount = $provider->count();
            if ($providerCount < 0) {
                throw new InvalidArgumentException('Approval queue provider returned a negative count.');
            }
            $count += $providerCount;
        }
        return $count;
    }

    /** @return list<ApprovalQueueItem> */
    public function latest(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Approval queue limit must be between 1 and 100.');
        }

        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->latest($limit) as $item) {
                if (!$item instanceof ApprovalQueueItem) {
                    throw new InvalidArgumentException('Approval queue provider returned an invalid item.');
                }
                if (($this->byType[$item->sourceType] ?? null) !== $provider) {
                    throw new InvalidArgumentException('Approval queue provider returned an unowned source type.');
                }
                $items[] = $item;
            }
        }
        usort(
            $items,
            static fn (ApprovalQueueItem $left, ApprovalQueueItem $right): int =>
                [$right->updatedAt->format('U.u'), $right->sourceType, $right->sourceId->value()]
                <=> [$left->updatedAt->format('U.u'), $left->sourceType, $left->sourceId->value()],
        );

        return array_slice($items, 0, $limit);
    }

    /** @param list<ApprovalQueueSelection> $selections */
    public function moderate(
        ApprovalQueueAction $action,
        array $selections,
        ModerationReasonCode $reason,
        ModerationRequestId $requestId,
        DateTimeImmutable $at,
    ): int {
        if ($selections === [] || count($selections) > 100) {
            throw new InvalidArgumentException('Approval queue bulk selection must contain between 1 and 100 items.');
        }

        /** @var array<int, array{provider:ApprovalQueueProvider,selections:list<ApprovalQueueSelection>}> $groups */
        $groups = [];
        $seen = [];
        foreach ($selections as $selection) {
            if (!$selection instanceof ApprovalQueueSelection) {
                throw new InvalidArgumentException('Approval queue bulk selection is invalid.');
            }
            $token = $selection->token();
            if (isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            $provider = $this->byType[$selection->sourceType] ?? null;
            if (!$provider instanceof ApprovalQueueProvider) {
                throw new InvalidArgumentException('Approval queue source type is not registered.');
            }
            $id = spl_object_id($provider);
            $groups[$id] ??= ['provider' => $provider, 'selections' => []];
            $groups[$id]['selections'][] = $selection;
        }

        foreach ($groups as $group) {
            $group['provider']->moderate($action, $group['selections'], $reason, $requestId, $at);
        }

        return count($seen);
    }

    /** @return list<string> */
    public function sourceTypes(): array
    {
        $types = array_keys($this->byType);
        sort($types, SORT_STRING);
        return $types;
    }
}
