<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentRecord;
use Forwext\Core\Forum\Node\ForumNodeId;
use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Upload\UploadedFile;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class AttachmentStageHandler implements RequestHandlerInterface
{
    public function __construct(
        private AttachmentServiceResolver $services,
        private ProfileViewerResolver $viewers,
        private UploadedAttachmentReader $uploads,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return Response::json(['error' => 'authentication_required'], 401)->withHeader('Cache-Control', 'no-store');
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $forumValue = is_array($parameters) ? ($parameters['forumId'] ?? null) : null;
        $upload = $request->uploads()['file'] ?? null;
        if (!is_string($forumValue) || !$upload instanceof UploadedFile || $upload->clientFilename === null) {
            return Response::json(['error' => 'invalid_attachment_request'], 400)->withHeader('Cache-Control', 'no-store');
        }
        try {
            $record = $this->services->forActor($actor)->stage(
                ForumNodeId::fromStored($forumValue),
                $upload->clientFilename,
                $this->uploads->read($upload, $this->services->quota()->maxFileBytes),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (PermissionDeniedException) {
            return Response::json(['error' => 'forbidden'], 403)->withHeader('Cache-Control', 'no-store');
        } catch (HttpException|InvalidArgumentException) {
            return Response::json(['error' => 'invalid_attachment_request'], 400)->withHeader('Cache-Control', 'no-store');
        } catch (AttachmentOperationException) {
            return Response::json(['error' => 'attachment_rejected'], 422)->withHeader('Cache-Control', 'no-store');
        }
        return Response::json($this->payload($record), 201)->withHeader('Cache-Control', 'private, no-store');
    }

    /** @return array<string,bool|int|string|null> */
    private function payload(AttachmentRecord $record): array
    {
        return [
            'attachment_id' => $record->attachmentId->value(), 'state' => $record->state->value,
            'filename' => $record->filename->value(), 'media_type' => $record->mediaType,
            'size_bytes' => $record->sizeBytes, 'image_width' => $record->imageWidth,
            'image_height' => $record->imageHeight, 'metadata_stripped' => $record->metadataStripped,
            'thumbnail_available' => $record->thumbnailPath !== null,
            'expires_at_utc' => $record->expiresAt->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
