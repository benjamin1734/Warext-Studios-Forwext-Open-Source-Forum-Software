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

final readonly class MinecraftServerVoteSettingsHandler implements RequestHandlerInterface
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
            $server = $this->servers->managementDetail($actor, $serverId);
            $integration = $this->servers->voteIntegrationSettings($actor, $serverId);
            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            $oneTimeToken = null;
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                $action = $body['action'] ?? null;
                if (!is_string($action)) {
                    throw new InvalidArgumentException('Minecraft vote integration action is missing.');
                }
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                if ($action === 'rotate_token') {
                    $oneTimeToken = $this->servers->rotateVoteIntegrationToken($actor, $serverId, $now);
                    $integration = $this->servers->voteIntegrationSettings($actor, $serverId);
                } elseif ($action === 'toggle') {
                    $this->servers->setVoteIntegrationEnabled(
                        $actor,
                        $serverId,
                        ($body['enabled'] ?? null) === '1',
                        $now,
                    );
                    return Response::redirect(
                        $this->basePath->prepend(
                            '/servers/' . rawurlencode($serverId->value()) . '/vote-settings?updated=1',
                        ),
                        303,
                    )->withHeader('Cache-Control', 'no-store');
                } else {
                    throw new InvalidArgumentException('Minecraft vote integration action is invalid.');
                }
            }

            return Response::html(MinecraftServerHtml::voteSettings(
                $server,
                $integration,
                $csrf,
                $this->basePath,
                $oneTimeToken,
                ($request->query()['updated'] ?? null) === '1',
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
