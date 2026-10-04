<?php

declare(strict_types=1);

namespace Forwext\App\Web\CommunityGroup;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\CommunityGroup\CommunityGroupMember;
use Forwext\Core\CommunityGroup\CommunityGroupService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use RuntimeException;

final readonly class CommunityGroupMineHandler implements RequestHandlerInterface
{
    public function __construct(
        private CommunityGroupService $groups,
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
            if ($request->method() === HttpMethod::Post) {
                $body = $request->parsedBody();
                if (($body['action'] ?? null) !== 'create') {
                    throw new InvalidArgumentException('Community group action is invalid.');
                }
                $this->groups->create(
                    $actor,
                    self::required($body, 'slug', 120),
                    self::required($body, 'name', 120),
                    self::optional($body, 'tagline', 240) ?? '',
                    self::required($body, 'description', 20000),
                    self::choice($body, 'join_policy', ['open','approval','closed']),
                    $this->groups->now(),
                );
                return Response::redirect($this->basePath->prepend('/groups/mine?created=1'), 303)
                    ->withHeader('Cache-Control', 'no-store');
            }

            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }
            $groups = $this->groups->mine($actor);
            $memberships = [];
            foreach ($groups as $group) {
                $membership = $this->groups->membership($group->groupId, $actor);
                if ($membership instanceof CommunityGroupMember) {
                    $memberships[$group->groupId->value()] = $membership;
                }
            }

            return Response::html(CommunityGroupHtml::mine(
                $groups,
                $memberships,
                $this->basePath,
                $csrf,
                $this->groups->canCreate($actor),
                ($request->query()['created'] ?? null) === '1',
            ))->withHeader('Cache-Control', 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
        }
    }

    /** @param array<string,mixed> $body */
    private static function required(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Community group field is missing.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Community group field is invalid.');
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
            throw new InvalidArgumentException('Community group field is invalid.');
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException('Community group field is invalid.');
        }
        return $value;
    }

    /** @param array<string,mixed> $body @param list<string> $choices */
    private static function choice(array $body, string $key, array $choices): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidArgumentException('Community group choice is invalid.');
        }
        return $value;
    }
}
