<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MinecraftServerUpdatesHandler implements RequestHandlerInterface
{
    private const PAGE_SIZE = 20;

    public function __construct(
        private MinecraftServerService $servers,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $serverId = self::serverId($request);
        if ($serverId === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
        $server = $this->servers->detail($serverId);
        if ($server === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $page = self::page($request);
            $rows = $this->servers->updates(
                $serverId,
                self::PAGE_SIZE + 1,
                ($page - 1) * self::PAGE_SIZE,
            );
            $hasMore = count($rows) > self::PAGE_SIZE;
            if ($hasMore) {
                array_pop($rows);
            }
            $actor = $this->viewers->resolve($request);

            return Response::html(MinecraftServerHtml::updates(
                $server,
                $rows,
                $page,
                $hasMore,
                $this->basePath,
                $actor !== null,
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    private static function serverId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['serverId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return null;
        }
        return EntityId::fromString($value);
    }

    private static function page(Request $request): int
    {
        $value = $request->query()['page'] ?? '1';
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,5}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Minecraft server update page is invalid.');
        }
        return (int) $value;
    }
}
