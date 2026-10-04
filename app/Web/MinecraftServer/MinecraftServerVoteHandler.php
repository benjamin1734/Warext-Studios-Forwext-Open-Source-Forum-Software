<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MinecraftServerVoteHandler implements RequestHandlerInterface
{
    public function __construct(
        private MinecraftServerService $servers,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }
        $serverId = self::serverId($request);
        if ($serverId === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $recorded = $this->servers->vote(
                $actor,
                $serverId,
                new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

            return Response::redirect(
                $this->basePath->prepend(
                    '/servers/' . rawurlencode($serverId->value()) . '?vote=' . ($recorded ? 'recorded' : 'already'),
                ),
                303,
            )->withHeader('Cache-Control', 'no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
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
}
