<?php

declare(strict_types=1);

namespace Forwext\App\Web\Portfolio;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Portfolio\PortfolioMediaService;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class PortfolioMediaDownloadHandler implements RequestHandlerInterface
{
    public function __construct(
        private PortfolioMediaService $media,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['mediaId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return Response::text('Not Found', 404);
        }

        $actor = $this->viewers->resolve($request);
        try {
            $download = $this->media->download(EntityId::fromString($value), $actor);
            return (new Response($download->contents))
                ->withHeader('Content-Type', $download->mediaType)
                ->withHeader('Content-Length', (string) strlen($download->contents))
                ->withHeader('Content-Disposition', "inline; filename*=UTF-8''" . rawurlencode($download->filename))
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', $actor === null ? 'public, max-age=3600' : 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|AttachmentOperationException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
    }
}
