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
    case ThreadReject = 'thread.reject';
    case ThreadDelete = 'thread.delete';
    case ThreadRestore = 'thread.restore';
    case PostApprove = 'post.approve';
    case PostReject = 'post.reject';
    case PostDelete = 'post.delete';
    case PostRestore = 'post.restore';
    case BulkThread = 'bulk.thread';
    case BulkPost = 'bulk.post';
    case WorkspaceTaskCreate = 'workspace.task_create';
    case WorkspaceTaskStatus = 'workspace.task_status';
    case ReportAssign = 'report.assign';
    case ReportStatus = 'report.status';
    case ReportComment = 'report.comment';
    case WarningDefinitionSave = 'discipline.warning_definition.save';
    case WarningIssue = 'discipline.warning.issue';
    case RestrictionApply = 'discipline.restriction.apply';
    case SuspensionApply = 'discipline.suspension.apply';
    case BanApply = 'discipline.ban.apply';
    case DisciplineRevoke = 'discipline.revoke';
}
