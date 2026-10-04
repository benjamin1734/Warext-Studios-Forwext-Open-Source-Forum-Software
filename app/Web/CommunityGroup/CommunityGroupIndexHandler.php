<?php

declare(strict_types=1);

namespace Forwext\App\Web\CommunityGroup;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\CommunityGroup\CommunityGroupService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;

final readonly class CommunityGroupIndexHandler implements RequestHandlerInterface
{
    private const PAGE_SIZE = 24;

    public function __construct(
        private CommunityGroupService $groups,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        try {
            $query = self::query($request);
            $page = self::page($request);
            $rows = $this->groups->directory(
                $actor,
                $query,
                self::PAGE_SIZE + 1,
                ($page - 1) * self::PAGE_SIZE,
            );
            $hasMore = count($rows) > self::PAGE_SIZE;
            if ($hasMore) {
                array_pop($rows);
            }

            return Response::html(CommunityGroupHtml::index(
                $rows,
                $query,
                $page,
                $hasMore,
                $this->basePath,
                $actor !== null,
                $actor !== null && $this->groups->canCreate($actor),
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private static function query(Request $request): ?string
    {
        $value = $request->query()['q'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Community group query is invalid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 120) {
            throw new InvalidArgumentException('Community group query is invalid.');
        }
        return $value;
    }

    private static function page(Request $request): int
    {
        $value = $request->query()['page'] ?? '1';
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,4}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Community group page is invalid.');
        }
        return (int) $value;
    }
}
