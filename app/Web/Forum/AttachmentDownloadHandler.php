<?php

declare(strict_types=1);

namespace Forwext\App\Web\Forum;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Forum\Attachment\AttachmentId;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class AttachmentDownloadHandler implements RequestHandlerInterface
{
    public function __construct(
        private AttachmentServiceResolver $services,
        private ProfileViewerResolver $viewers,
        private AttachmentDownloadResponseFactory $responses,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) return Response::json(['error' => 'authentication_required'], 401)->withHeader('Cache-Control', 'no-store');
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $attachmentValue = is_array($parameters) ? ($parameters['attachmentId'] ?? null) : null;
        if (!is_string($attachmentValue)) return Response::text('Not Found', 404);
        $thumbnailValue = $request->query()['thumbnail'] ?? null;
        $thumbnail = $thumbnailValue === '1' || $thumbnailValue === 1 || $thumbnailValue === true;
        try {
            $download = $this->services->forActor($actor)->download(AttachmentId::fromStored($attachmentValue), $thumbnail);
        } catch (PermissionDeniedException) {
            return Response::json(['error' => 'forbidden'], 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|AttachmentOperationException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
        return $this->responses->create($download);
    }
}
