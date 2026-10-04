<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;

final readonly class MinecraftServerVoteFeedHandler implements RequestHandlerInterface
{
    public function __construct(private MinecraftServerService $servers)
    {
    }

    public function handle(Request $request): Response
    {
        $serverId = self::serverId($request);
        if ($serverId === null) {
            return Response::json(['error'=>'not_found'], 404)->withHeader('Cache-Control', 'no-store');
        }
        $authorization = $request->headers()->first('authorization');
        if (!is_string($authorization)
            || preg_match('/^Bearer ([A-Za-z0-9_-]{40,128})$/D', $authorization, $match) !== 1) {
            return self::unauthorized();
        }

        try {
            $limit = self::limit($request);
        } catch (InvalidArgumentException) {
            return Response::json(['error'=>'bad_request'], 400)
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        }

        try {
            $rows = $this->servers->voteIntegrationFeed($serverId, $match[1], $limit);
            $votes = array_map(
                static fn ($vote): array => [
                    'vote_id'=>$vote->voteId->value(),
                    'account_user_id'=>$vote->voterUserId->value(),
                    'account_username'=>$vote->accountUsername,
                    'voted_at_utc'=>$vote->createdAt->format(DATE_ATOM),
                ],
                $rows,
            );

            return Response::json([
                'server_id'=>$serverId->value(),
                'order'=>'newest_first',
                'count'=>count($votes),
                'votes'=>$votes,
            ])->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Content-Type-Options', 'nosniff');
        } catch (InvalidArgumentException) {
            return self::unauthorized();
        }
    }

    private static function unauthorized(): Response
    {
        return Response::json(['error'=>'unauthorized'], 401)
            ->withHeader('WWW-Authenticate', 'Bearer realm="minecraft-vote-feed"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
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

    private static function limit(Request $request): int
    {
        $value = $request->query()['limit'] ?? '100';
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Minecraft vote integration feed limit is invalid.');
        }
        $limit = (int) $value;
        if ($limit < 1 || $limit > 200) {
            throw new InvalidArgumentException('Minecraft vote integration feed limit is invalid.');
        }
        return $limit;
    }
}
