<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Forum\Attachment\AttachmentId;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Post\PostId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class AttachmentFinalizeHandler implements RequestHandlerInterface
{
    public function __construct(private AttachmentServiceResolver $services, private ProfileViewerResolver $viewers) {}

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return Response::json(['error' => 'authentication_required'], 401)->withHeader('Cache-Control', 'no-store');
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $attachmentValue = is_array($parameters) ? ($parameters['attachmentId'] ?? null) : null;
        $postValue = $request->parsedBody()['post_id'] ?? null;
        if (!is_string($attachmentValue) || !is_string($postValue)) return Response::json(['error' => 'invalid_attachment_request'], 400)->withHeader('Cache-Control', 'no-store');
        try {
            $record = $this->services->forActor($actor)->finalize(
                AttachmentId::fromStored($attachmentValue),
                PostId::fromStored($postValue),
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );
        } catch (PermissionDeniedException) {
            return Response::json(['error' => 'forbidden'], 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::json(['error' => 'invalid_attachment_request'], 400)->withHeader('Cache-Control', 'no-store');
        } catch (AttachmentOperationException) {
            return Response::json(['error' => 'attachment_not_finalizable'], 409)->withHeader('Cache-Control', 'no-store');
        }
        return Response::json([
            'attachment_id' => $record->attachmentId->value(),
            'state' => $record->state->value,
            'post_id' => $record->postId?->value(),
            'thumbnail_available' => $record->thumbnailPath !== null,
            'attached_at_utc' => $record->attachedAt?->format('Y-m-d\TH:i:s.u\Z'),
        ])->withHeader('Cache-Control', 'private, no-store');
    }
}
