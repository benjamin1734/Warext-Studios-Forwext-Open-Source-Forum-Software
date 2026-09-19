<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Freshness;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class ThreadFreshnessNotifier
{
    public const STALE_WARNING = 'forum.thread.freshness.stale';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::STALE_WARNING,
            'thread_freshness',
            'Konunuzun güncelliğini kontrol edin',
            '{{title}} başlıklı konunuz {{days}} gündür güncellenmedi. Gerekliyse konuyu yenileyebilirsiniz.',
        ));
    }

    public function staleWarning(ThreadFreshnessSnapshot $snapshot): void
    {
        if ($snapshot->authorUserId === null) {
            return;
        }
        $this->dispatcher->dispatch(new NotificationRequest(
            $snapshot->authorUserId,
            self::STALE_WARNING,
            ['title'=>$snapshot->title,'days'=>(string) $snapshot->ageDays],
            null,
            'thread-freshness:' . $snapshot->threadId->value() . ':' . $snapshot->lastActivityAt->getTimestamp(),
            '/threads/' . $snapshot->threadId->value() . '/freshness',
            ['thread_id'=>$snapshot->threadId->value(),'age_days'=>$snapshot->ageDays],
        ));
    }
}
