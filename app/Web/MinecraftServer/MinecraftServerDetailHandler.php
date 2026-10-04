<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;

final readonly class MinecraftServerDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private MinecraftServerService $servers,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $serverId = $this->serverId($request);
        if ($serverId === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
        $server = $this->servers->detail($serverId);
        if ($server === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
        $actor = $this->viewers->resolve($request);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $voteSummary = $this->servers->voteSummary($serverId, $actor, $now);
        $canVote = $actor !== null && $this->servers->canVote($actor, $voteSummary);
        $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
        if ($canVote && (!is_string($csrf) || $csrf === '')) {
            return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
        }
        $voteStatus = $request->query()['vote'] ?? null;
        if (!is_string($voteStatus) || !in_array($voteStatus, ['recorded','already'], true)) {
            $voteStatus = null;
        }

        return Response::html(MinecraftServerHtml::detail(
            $server,
            $this->basePath,
            $actor !== null,
            $actor !== null && $this->servers->canManage($actor, $server),
            $actor !== null && $this->servers->canClaim($actor, $server),
            $voteSummary,
            $canVote,
            $canVote && is_string($csrf) ? $csrf : null,
            $voteStatus,
        ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
    }

    private function serverId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['serverId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return null;
        }
        return EntityId::fromString($value);
    }
}
