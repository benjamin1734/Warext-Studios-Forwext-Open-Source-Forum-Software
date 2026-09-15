<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\Core\Forum\Attachment\AttachmentDownload;
use Forwext\Core\Http\Response;

final class AttachmentDownloadResponseFactory
{
    public function create(AttachmentDownload $download): Response
    {
        $disposition = $download->thumbnail ? 'inline' : 'attachment';
        $encoded = rawurlencode($download->filename);

        return (new Response($download->contents, 200))
            ->withHeader('Content-Type', $download->mediaType)
            ->withHeader('Content-Length', (string) strlen($download->contents))
            ->withHeader('Content-Disposition', $disposition . "; filename*=UTF-8''" . $encoded)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }
}
