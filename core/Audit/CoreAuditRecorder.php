<?php

declare(strict_types=1);

namespace Forwext\Core\Audit;

use DateTimeImmutable;
use Forwext\Core\Database\TransactionalQueryExecutor;

final readonly class CoreAuditRecorder implements AuditRecorder
{
    public function __construct(
        private TransactionalQueryExecutor $database,
        private AuditEventStore $store,
    ) {
    }

    public function append(AuditEvent $event): void
    {
        if ($this->database->inTransaction()) {
            $this->store->append($event);
            return;
        }
        $this->database->transaction(function () use ($event): void {
            $this->store->append($event);
        });
    }

    /** @template T @param callable():T $mutation @return T */
    public function mutate(AuditEvent $event, callable $mutation): mixed
    {
        return $this->database->transaction(function () use ($event, $mutation): mixed {
            $result = $mutation();
            $this->store->append($event);
            return $result;
        });
    }
}
