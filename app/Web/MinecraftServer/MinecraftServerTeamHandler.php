<?php

declare(strict_types=1);

namespace Forwext\App\Web\MinecraftServer;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\Username;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Minecraft\Server\MinecraftServerTeamMember;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;
use RuntimeException;

final readonly class MinecraftServerTeamHandler implements RequestHandlerInterface
{
    public function __construct(
        private MinecraftServerService $servers,
        private UserRepository $users,
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
        $actor = $this->viewers->resolve($request);

        try {
            $server = $this->servers->detail($serverId);
            if ($server === null) {
                if ($actor === null) {
                    return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
                }
                $server = $this->servers->managementDetail($actor, $serverId);
            }

            if ($request->method() === HttpMethod::Post) {
                if ($actor === null) {
                    return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
                }
                $this->mutate($actor, $serverId, $request);

                return Response::redirect(
                    $this->basePath->prepend('/servers/' . rawurlencode($serverId->value()) . '/team?updated=1'),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $canManage = $actor !== null && $this->servers->canManageTeam($actor, $server);
            $team = $canManage
                ? $this->servers->teamForManagement($actor, $serverId)
                : $this->servers->publicTeam($serverId);
            if (!$canManage) {
                $team = array_values(array_filter(
                    $team,
                    fn (MinecraftServerTeamMember $member): bool => $this->activeUsername($member->userId) !== null,
                ));
            }

            $csrf = null;
            if ($canManage) {
                $csrfValue = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
                if (!is_string($csrfValue) || $csrfValue === '') {
                    return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
                }
                $csrf = $csrfValue;
            }

            $ownerName = $server->ownerUserId === null
                ? null
                : $this->users->find($server->ownerUserId)?->username()->display();

            return Response::html(MinecraftServerHtml::team(
                $server,
                $team,
                $this->teamNames($team),
                $ownerName,
                $canManage,
                $csrf,
                $this->basePath,
                $actor !== null,
                ($request->query()['updated'] ?? null) === '1',
                $actor !== null && $this->servers->canManageVoteIntegration($actor, $server),
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, EntityId $serverId, Request $request): void
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Minecraft server team action is missing.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($action === 'save_member') {
            $username = Username::fromString(self::required($body, 'username', 64));
            $user = $this->users->findByUsername($username);
            if ($user === null || $user->status() !== UserStatus::Active) {
                throw new InvalidArgumentException('Minecraft server team target is unavailable.');
            }
            $this->servers->saveTeamMember(
                $actor,
                $serverId,
                $user->id(),
                self::choice($body, 'role_key', ['manager','member']),
                self::optional($body, 'public_title', 64),
                $now,
            );
            return;
        }

        if ($action === 'remove_member') {
            $this->servers->removeTeamMember(
                $actor,
                $serverId,
                self::id($body['user_id'] ?? null),
                $now,
            );
            return;
        }

        throw new InvalidArgumentException('Minecraft server team action is invalid.');
    }

    /** @param list<MinecraftServerTeamMember> $team @return array<string,string> */
    private function teamNames(array $team): array
    {
        $names = [];
        foreach ($team as $member) {
            $user = $this->users->find($member->userId);
            $names[$member->userId->value()] = $user?->username()->display() ?? 'Hesap kullanılamıyor';
        }
        return $names;
    }

    private function activeUsername(EntityId $userId): ?string
    {
        $user = $this->users->find($userId);
        return $user !== null && $user->status() === UserStatus::Active
            ? $user->username()->display()
            : null;
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

    private static function id(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Minecraft server team user id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Minecraft server team field is invalid.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Minecraft server team field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optional(array $body, string $key, int $max): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('Minecraft server team optional field is invalid.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Minecraft server team optional field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body @param list<string> $choices */
    private static function choice(array $body, string $key, array $choices): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidArgumentException('Minecraft server team choice is invalid.');
        }
        return $value;
    }
}
