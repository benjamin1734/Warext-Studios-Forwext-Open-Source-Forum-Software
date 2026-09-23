<?php

declare(strict_types=1);

namespace Forwext\App\Web\Appearance;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Ui\Layout\Builder\LayoutBuilderService;
use InvalidArgumentException;

final readonly class LayoutBuilderExportHandler implements RequestHandlerInterface
{
    private const LAYOUT_KEY = 'site.default';

    public function __construct(
        private LayoutBuilderService $builder,
        private ProfileViewerResolver $viewers,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            return Response::text($this->builder->export($actor, self::LAYOUT_KEY))
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="forwext-layout-site.default.json"')
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
    }
}
