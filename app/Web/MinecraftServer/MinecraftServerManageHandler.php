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
use Forwext\Core\Minecraft\Server\MinecraftServerClaim;
use Forwext\Core\Minecraft\Server\MinecraftServerService;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;
use RuntimeException;

final readonly class MinecraftServerManageHandler implements RequestHandlerInterface
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
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $serverId = self::serverId($request);
            if ($serverId === null) {
                if ($request->method() !== HttpMethod::Get) {
                    return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
                }
                $claims = $this->servers->canReviewClaims($actor)
                    ? $this->servers->claimsForManagement($actor)
                    : [];

                return Response::html(MinecraftServerHtml::manageIndex(
                    $this->servers->manageable($actor, 100),
                    $claims,
                    $this->claimantNames($claims),
                    $this->basePath,
                ))->withHeader('Cache-Control', 'private, no-store')
                    ->withHeader('X-Robots-Tag', 'noindex,nofollow');
            }

            $server = $this->servers->managementDetail($actor, $serverId);
            if ($request->method() === HttpMethod::Post) {
                $action = $request->parsedBody()['action'] ?? null;
                $this->mutate($actor, $serverId, $request);

                $redirectPath = match ($action) {
                    'save' => '/servers/' . rawurlencode($serverId->value()) . '/edit?updated=1',
                    'transfer', 'release' => '/servers/' . rawurlencode($serverId->value()),
                    default => '/servers/' . rawurlencode($serverId->value()) . '/manage?updated=1',
                };

                return Response::redirect(
                    $this->basePath->prepend($redirectPath),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }
            $claims = $this->servers->canReviewClaims($actor)
                ? $this->servers->claimsForManagement($actor, $serverId)
                : [];

            return Response::html(MinecraftServerHtml::manage(
                $server,
                $claims,
                $this->claimantNames($claims),
                $this->servers->managementUpdates($actor, $serverId, 50),
                $csrf,
                $this->basePath,
                $this->servers->canReviewClaims($actor),
                ($request->query()['updated'] ?? null) === '1',
                $this->servers->canManageTeam($actor, $server),
                $this->servers->canManageVoteIntegration($actor, $server),
                $this->servers->canManageOwnership($actor, $server),
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

    private function mutate(EntityId $actor, EntityId $serverId, Request $request): void
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Minecraft server management action is missing.');
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($action === 'save') {
            $this->servers->update(
                $actor,
                $serverId,
                self::required($body, 'name', 120),
                self::required($body, 'summary', 240, allowEmpty:true),
                self::required($body, 'description', 20000, allowEmpty:true),
                self::required($body, 'host', 255),
                self::port($body['port'] ?? null),
                self::choice($body, 'edition', ['java','bedrock','crossplay']),
                self::required($body, 'version_label', 64, allowEmpty:true),
                self::required($body, 'game_mode', 64, allowEmpty:true),
                self::optional($body, 'website_url', 1000),
                self::optional($body, 'discord_url', 1000),
                self::choice($body, 'listing_state', ['draft','published','suspended']),
                $now,
            );
            return;
        }

        if ($action === 'transfer') {
            $username = Username::fromString(self::required($body, 'target_username', 64));
            $target = $this->users->findByUsername($username);
            if ($target === null || $target->status() !== UserStatus::Active) {
                throw new InvalidArgumentException('Transfer target is unavailable.');
            }
            $this->servers->transfer($actor, $serverId, $target->id(), $now);
            return;
        }

        if ($action === 'release') {
            $this->servers->release($actor, $serverId, $now);
            return;
        }

        if ($action === 'claim_review') {
            $claimId = self::id($body['claim_id'] ?? null);
            $decision = self::choice($body, 'decision', ['approve','reject']);
            $this->servers->reviewClaim(
                $actor,
                $claimId,
                $decision === 'approve',
                self::optional($body, 'review_note', 1000),
                $now,
            );
            return;
        }

        if ($action === 'publish_update') {
            $this->servers->publishUpdate(
                $actor,
                $serverId,
                self::required($body, 'update_title', 160),
                self::required($body, 'update_body', 10000),
                $now,
            );
            return;
        }

        if ($action === 'update_state') {
            $this->servers->changeUpdateState(
                $actor,
                $serverId,
                self::id($body['update_id'] ?? null),
                self::choice($body, 'update_state', ['published','hidden']),
                $now,
            );
            return;
        }

        throw new InvalidArgumentException('Minecraft server management action is invalid.');
    }

    /** @param list<MinecraftServerClaim> $claims @return array<string,string> */
    private function claimantNames(array $claims): array
    {
        $names = [];
        foreach ($claims as $claim) {
            $key = $claim->claimantUserId->value();
            if (isset($names[$key])) {
                continue;
            }
            $user = $this->users->find($claim->claimantUserId);
            $names[$key] = $user?->username()->display() ?? $key;
        }
        return $names;
    }

    private static function serverId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['serverId'] ?? null) : null;
        if ($value === null) {
            return null;
        }
        return self::id($value);
    }

    private static function id(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Minecraft server id is invalid.');
        }
        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max, bool $allowEmpty = false): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Minecraft server field is invalid.');
        }
        $value = trim($value);
        if ((!$allowEmpty && $value === '') || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Minecraft server field is invalid.');
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
            throw new InvalidArgumentException('Minecraft server optional field is invalid.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Minecraft server optional field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body @param list<string> $choices */
    private static function choice(array $body, string $key, array $choices): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidArgumentException('Minecraft server choice is invalid.');
        }
        return $value;
    }

    private static function port(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,5}$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > 65535) {
            throw new InvalidArgumentException('Minecraft server port is invalid.');
        }
        return $value;
    }
}
