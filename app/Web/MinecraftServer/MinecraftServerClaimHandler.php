<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;
use RuntimeException;

final readonly class MinecraftServerClaimHandler implements RequestHandlerInterface
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
        $server = $this->servers->detail($serverId);
        if ($server === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }

        try {
            if (!$this->servers->canClaim($actor, $server)) {
                return Response::text(
                    $server->ownerUserId === null ? 'Forbidden' : 'Ownership unavailable.',
                    $server->ownerUserId === null ? 403 : 409,
                )->withHeader('Cache-Control', 'no-store');
            }

            if ($request->method() === HttpMethod::Post) {
                $proof = $request->parsedBody()['proof_note'] ?? null;
                if (!is_string($proof)) {
                    throw new InvalidArgumentException('Ownership proof is missing.');
                }
                $this->servers->submitClaim(
                    $actor,
                    $serverId,
                    $proof,
                    new DateTimeImmutable('now', new DateTimeZone('UTC')),
                );

                return Response::redirect(
                    $this->basePath->prepend('/servers/' . rawurlencode($serverId->value()) . '/claim?submitted=1'),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            return Response::html(MinecraftServerHtml::claim(
                $server,
                $this->servers->claimsForActor($actor, $serverId),
                $csrf,
                $this->basePath,
                ($request->query()['submitted'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
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
