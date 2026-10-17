<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\AdminInformationArchitectureService;
use Forwext\Core\Admin\Navigation\AdminNavigationSection;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;

/**
 * Read-only, permission-scoped ACP navigation for every management page.
 *
 * The main ACP and every module page use the same source of truth. No route or
 * label is serialized until the current viewer's permissions have been checked.
 */
final readonly class AdminSidebarNavigationHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminInformationArchitectureService $administration,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return $this->response(['error' => 'Authentication required.'], 401);
        }

        try {
            $sections = [];
            foreach ($this->administration->navigationSections($actor) as $key => $items) {
                $links = [];
                foreach ($items as $item) {
                    $links[] = [
                        'key' => $item->key,
                        'label' => $item->label,
                        'href' => $this->basePath->prepend($item->path),
                    ];
                }

                $sections[] = [
                    'key' => $key,
                    'label' => AdminNavigationSection::from($key)->label(),
                    'items' => $links,
                ];
            }

            return $this->response(['sections' => $sections]);
        } catch (PermissionDeniedException) {
            return $this->response(['error' => 'Forbidden.'], 403);
        }
    }

    /** @param array<string, mixed> $payload */
    private function response(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status)
            ->withHeader('Cache-Control', 'private, no-store')
            ->withHeader('X-Robots-Tag', 'noindex,nofollow')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
