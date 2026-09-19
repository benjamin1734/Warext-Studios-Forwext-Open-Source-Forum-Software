<?php

declare(strict_types=1);

namespace Forwext\App\Web\Portfolio;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Portfolio\PortfolioService;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

final readonly class PortfolioIndexHandler implements RequestHandlerInterface
{
    public function __construct(
        private PortfolioService $portfolio,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        try {
            $projects = $this->portfolio->projects($actor, null, false, 100);
            return Response::html(PortfolioHtml::index(
                $projects,
                $this->portfolio->categories(),
                $this->basePath,
                $actor !== null,
                $actor !== null && $this->portfolio->canCreate($actor),
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        }
    }
}
