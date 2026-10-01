<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class ForumAttachmentComposerWebSurfaceTest extends TestCase
{
    public function testComposerStagesAndSubmitsOnlyServerValidatedAttachmentIds(): void
    {
        $root = dirname(__DIR__, 4);
        $editor = (string) file_get_contents($root . '/app/Web/Editor/RichEditorView.php');
        $javascript = (string) file_get_contents($root . '/public/assets/rich-editor.js');
        $create = (string) file_get_contents($root . '/app/Web/Forum/ThreadCreateHandler.php');
        $reply = (string) file_get_contents($root . '/app/Web/Forum/ThreadReplyHandler.php');
        $input = (string) file_get_contents($root . '/app/Web/Forum/StagedAttachmentInput.php');

        self::assertStringContainsString('data-attachment-stage-url=', $editor);
        self::assertStringContainsString('data-attachment-csrf-url=', $editor);
        self::assertStringContainsString('name="attachment_ids[]"', $editor);
        self::assertStringContainsString('data-fx-editor-attachment-input', $editor);

        self::assertStringContainsString("'X-CSRF-Token': token", $javascript);
        self::assertStringContainsString("form.append('file', file, file.name)", $javascript);
        self::assertStringContainsString('data-attachment-busy="1"', $javascript);
        self::assertStringContainsString("hidden.name = 'attachment_ids[]'", $javascript);

        self::assertStringContainsString('StagedAttachmentInput::fromRequest($request)', $create);
        self::assertStringContainsString('validateTemporaryForForum($attachmentId, $forum->id(), $now)', $create);
        self::assertStringContainsString('$published->firstPost->id()', $create);
        self::assertStringContainsString('$service->finalize($attachmentId, $postId, $now)', $create);

        self::assertStringContainsString('StagedAttachmentInput::fromRequest($request)', $reply);
        self::assertStringContainsString('validateTemporaryForForum($attachmentId, $forum->id(), $now)', $reply);
        self::assertStringContainsString('$service->finalize($attachmentId, $postId, $now)', $reply);

        self::assertStringContainsString('MAX_ATTACHMENTS_PER_SUBMISSION = 20', $input);
        self::assertStringContainsString("preg_match('/^[a-f0-9]{32}$/D'", $input);
    }

    public function testThreadViewUsesPermissionAwareBatchedAttachmentRendering(): void
    {
        $root = dirname(__DIR__, 4);
        $thread = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');
        $repository = (string) file_get_contents($root . '/core/Forum/Attachment/DatabaseAttachmentRepository.php');
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $css = (string) file_get_contents($root . '/public/assets/site-components.css')
            . (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('AttachmentPermission::Download->key()', $thread);
        self::assertStringContainsString('$this->attachments->attachedForPosts($postIds)', $thread);
        self::assertStringContainsString('renderAttachments($attachments)', $thread);
        self::assertStringContainsString('thread-attachment-thumb', $thread);
        self::assertStringContainsString("'thread-quick-reply-editor'", $thread);
        self::assertStringContainsString('$forum->id()', $thread);

        self::assertStringContainsString('public function attachedForPosts(array $postIds): array', $repository);
        self::assertStringContainsString("WHERE `state` = 'attached'", $repository);
        self::assertStringContainsString('ORDER BY `post_id` ASC', $repository);

        self::assertStringContainsString("'forum.attachment.csrf'", $factory);
        self::assertStringContainsString("new PathTemplate('/attachments/csrf')", $factory);
        self::assertStringContainsString('new AttachmentCsrfTokenHandler($viewerResolver)', $factory);
        self::assertStringContainsString('$attachmentRepository,', $factory);

        self::assertStringContainsString('.thread-attachments{', $css);
        self::assertStringContainsString('.thread-attachment{', $css);
    }

    public function testAttachmentFinalizeFailureDoesNotInviteDuplicateContentSubmission(): void
    {
        $root = dirname(__DIR__, 4);
        $create = (string) file_get_contents($root . '/app/Web/Forum/ThreadCreateHandler.php');
        $reply = (string) file_get_contents($root . '/app/Web/Forum/ThreadReplyHandler.php');
        $thread = (string) file_get_contents($root . '/app/Web/Forum/ThreadViewHandler.php');

        self::assertStringContainsString("(\$attachmentWarning ? '?attachment_warning=1' : '')", $create);
        self::assertStringContainsString("'Forwext attachment finalize failed after thread publish.'", $create);
        self::assertStringContainsString("'Forwext attachment finalize failed after reply publish.'", $reply);
        self::assertStringContainsString("['attachment_warning']", $thread);
        self::assertStringContainsString('bir veya daha fazla dosya mesaja bağlanamadı', $thread);
    }
}
