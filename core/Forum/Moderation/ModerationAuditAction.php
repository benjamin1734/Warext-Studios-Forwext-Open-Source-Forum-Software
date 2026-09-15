<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Moderation;

enum ModerationAuditAction: string
{
    case ThreadMove = 'thread.move';
    case ThreadCopy = 'thread.copy';
    case ThreadMerge = 'thread.merge';
    case ThreadSplit = 'thread.split';
    case ThreadLock = 'thread.lock';
    case ThreadUnlock = 'thread.unlock';
    case ThreadSticky = 'thread.sticky';
    case ThreadUnsticky = 'thread.unsticky';
    case ThreadApprove = 'thread.approve';
    case ThreadDelete = 'thread.delete';
    case ThreadRestore = 'thread.restore';
    case PostApprove = 'post.approve';
    case PostDelete = 'post.delete';
    case PostRestore = 'post.restore';
    case BulkThread = 'bulk.thread';
    case BulkPost = 'bulk.post';
}
