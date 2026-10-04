<?php

declare(strict_types=1);

namespace Forwext\App\Web\CommunityGroup;

use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\CommunityGroup\CommunityGroupMember;
use Forwext\Core\CommunityGroup\CommunityGroupService;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserRepository;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use Forwext\Core\Routing\Router;
use InvalidArgumentException;
use RuntimeException;

final readonly class CommunityGroupDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private CommunityGroupService $groups,
        private UserRepository $users,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
    ) {
    }

    public function handle(Request $request): Response
    {
        $groupId = self::groupId($request);
        if ($groupId === null) {
            return Response::text('Not Found', 404)->withHeader('Cache-Control', 'no-store');
        }
        $actor = $this->viewers->resolve($request);

        try {
            $group = $this->groups->detail($groupId, $actor);
            if ($request->method() === HttpMethod::Post) {
                if ($actor === null) {
                    return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
                }
                $status = $this->mutate($actor, $groupId, $request);
                return Response::redirect(
                    $this->basePath->prepend(
                        '/groups/' . rawurlencode($groupId->value()) . '?status=' . rawurlencode($status),
                    ),
                    303,
                )->withHeader('Cache-Control', 'no-store');
            }

            $csrf = null;
            if ($actor !== null) {
                $token = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
                if (!is_string($token) || $token === '') {
                    return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
                }
                $csrf = $token;
            }
            $members = $this->groups->members($groupId, $actor);
            $names = [];
            foreach ($members as $member) {
                $user = $this->users->find($member->userId);
                $names[$member->userId->value()] = $user !== null && $user->status() === UserStatus::Active
                    ? $user->username()->display()
                    : 'Hesap kullanılamıyor';
            }
            $membership = $actor === null ? null : $this->groups->membership($groupId, $actor);
            $canManage = $actor !== null && $this->groups->canManage($actor, $group);

            return Response::html(CommunityGroupHtml::detail(
                $group,
                $members,
                $names,
                $membership,
                $this->basePath,
                $actor !== null,
                $csrf,
                $canManage,
                $actor !== null && $canManage && $this->groups->canManageRoles($actor, $group),
                self::status($request),
            ))->withHeader('Cache-Control', $actor === null ? 'public, max-age=60' : 'private, no-store');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        } catch (RuntimeException) {
            return Response::text('Conflict', 409)->withHeader('Cache-Control', 'no-store');
        }
    }

    private function mutate(EntityId $actor, EntityId $groupId, Request $request): string
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('Community group action is missing.');
        }

        if ($action === 'join') {
            return $this->groups->join($actor, $groupId, $this->groups->now()) === 'active'
                ? 'joined'
                : 'requested';
        }
        if ($action === 'leave') {
            $this->groups->leave($actor, $groupId, $this->groups->now());
            return 'left';
        }
        if ($action === 'manage_member') {
            $memberAction = $body['member_action'] ?? null;
            if (!is_string($memberAction) || !in_array($memberAction, ['approve','promote','demote','remove'], true)) {
                throw new InvalidArgumentException('Community group member action is invalid.');
            }
            $this->groups->manageMembership(
                $actor,
                $groupId,
                self::userId($body['user_id'] ?? null),
                $memberAction,
                $this->groups->now(),
            );
            return 'managed';
        }

        throw new InvalidArgumentException('Community group action is invalid.');
    }

    private static function groupId(Request $request): ?EntityId
    {
        $parameters = $request->attribute(Router::ATTRIBUTE_ROUTE_PARAMETERS, []);
        $value = is_array($parameters) ? ($parameters['groupId'] ?? null) : null;
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            return null;
        }
        return EntityId::fromString($value);
    }

    private static function userId(mixed $value): EntityId
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('Community group user id is invalid.');
        }
        return EntityId::fromString($value);
    }

    private static function status(Request $request): ?string
    {
        $value = $request->query()['status'] ?? null;
        return is_string($value) && in_array($value, ['joined','requested','left','managed','created'], true)
            ? $value
            : null;
    }
}
