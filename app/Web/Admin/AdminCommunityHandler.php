<?php

declare(strict_types=1);

namespace Forwext\App\Web\Admin;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Forwext\App\Web\Audit\HttpAuditRequestId;
use Forwext\App\Web\Profile\ProfileHtml;
use Forwext\App\Web\Profile\ProfileViewerResolver;
use Forwext\Core\Admin\Community\AdminCommunitySection;
use Forwext\Core\Admin\Community\AdminCommunityService;
use Forwext\Core\Domain\Access\Appearance\RoleAppearance;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceAnimation;
use Forwext\Core\Domain\Access\Appearance\RoleAppearanceIcon;
use Forwext\Core\Domain\Access\Appearance\RoleAppearancePattern;
use Forwext\Core\Domain\Access\Appearance\RoleColor;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\RoleKind;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserStatus;
use Forwext\Core\Forum\Node\ForumDefaultThreadSort;
use Forwext\Core\Forum\Node\ForumNode;
use Forwext\Core\Forum\Node\ForumNodeLinkTarget;
use Forwext\Core\Forum\Node\ForumNodeSlug;
use Forwext\Core\Forum\Node\ForumNodeType;
use Forwext\Core\Forum\Node\ForumNodeVisibility;
use Forwext\Core\Forum\Node\ForumSettings;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Middleware\RequestHandlerInterface;
use Forwext\Core\Http\Request;
use Forwext\Core\Http\Response;
use Forwext\Core\Http\Security\Csrf\CsrfMiddleware;
use Forwext\Core\Routing\BasePath;
use InvalidArgumentException;
use ValueError;

final readonly class AdminCommunityHandler implements RequestHandlerInterface
{
    public function __construct(
        private AdminCommunityService $community,
        private ProfileViewerResolver $viewers,
        private BasePath $basePath,
        private AdminCommunitySection $section,
    ) {
    }

    public function handle(Request $request): Response
    {
        $actor = $this->viewers->resolve($request);
        if ($actor === null) {
            return Response::text('Authentication required.', 401)->withHeader('Cache-Control', 'no-store');
        }

        try {
            $csrf = $request->attribute(CsrfMiddleware::ATTRIBUTE_TOKEN);
            if (!is_string($csrf) || $csrf === '') {
                return Response::text('Internal Server Error', 500)->withHeader('Cache-Control', 'no-store');
            }

            if ($request->method() === HttpMethod::Post) {
                return $this->mutate($actor, $request);
            }

            $snapshot = $this->snapshot($actor, $request);
            $content = AdminCommunityHtml::page($this->section, $snapshot, $this->basePath, $csrf, $actor);

            return Response::html(ProfileHtml::page(
                $this->section->label() . ' · Administration',
                $content,
                $this->basePath,
                authenticated: true,
                viewerId: $actor->value(),
            ))
                ->withHeader('Cache-Control', 'private, no-store')
                ->withHeader('X-Robots-Tag', 'noindex,nofollow');
        } catch (PermissionDeniedException) {
            return Response::text('Forbidden', 403)->withHeader('Cache-Control', 'no-store');
        } catch (InvalidArgumentException|DomainException|ValueError) {
            return Response::text('Bad Request', 400)->withHeader('Cache-Control', 'no-store');
        }
    }

    /** @return array<string,mixed> */
    private function snapshot(EntityId $actor, Request $request): array
    {
        $query = $request->query();

        return match ($this->section) {
            AdminCommunitySection::Users => [
                'users'=>$this->community->usersSnapshot(
                    $actor,
                    self::queryString($query, 'q', 80),
                    self::optionalHexId($query['user'] ?? null),
                ),
                'access'=>$this->community->accessSnapshot($actor, null, null, '', null),
            ],
            AdminCommunitySection::Access => [
                'access'=>$this->community->accessSnapshot(
                    $actor,
                    self::optionalStoredId($query['role'] ?? null),
                    self::optionalHexId($query['analyze_user'] ?? null),
                    self::queryString($query, 'permission', 96),
                    self::optionalHexId($query['node'] ?? null),
                ),
            ],
            AdminCommunitySection::Forums => [
                'forums'=>$this->community->forumsSnapshot(
                    $actor,
                    self::optionalHexId($query['node'] ?? null),
                ),
            ],
            AdminCommunitySection::Content,
            AdminCommunitySection::Moderation => [
                'operations'=>$this->community->operationsSnapshot($actor),
            ],
        };
    }

    private function mutate(EntityId $actor, Request $request): Response
    {
        $body = $request->parsedBody();
        $action = $body['action'] ?? null;
        if (!is_string($action)) {
            throw new InvalidArgumentException('ACP community action is missing.');
        }

        $requestId = HttpAuditRequestId::fromRequest($request);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        if ($this->section === AdminCommunitySection::Users) {
            $userId = self::requiredHexId($body, 'user_id');
            if ($action === 'replace_access') {
                $primary = self::optionalString($body, 'primary_group_id', 191);
                $this->community->replaceUserAccess(
                    $actor,
                    $userId,
                    $primary,
                    self::stringList($body['secondary_group_ids'] ?? [], 100, 191),
                    self::stringList($body['role_ids'] ?? [], 100, 191),
                    $requestId,
                    $now,
                );
            } elseif ($action === 'change_status') {
                $this->community->changeUserStatus(
                    $actor,
                    $userId,
                    UserStatus::from(self::requiredString($body, 'status', 32)),
                    self::requiredString($body, 'reason', 120),
                    $requestId,
                    $now,
                );
            } else {
                throw new InvalidArgumentException('Unknown ACP user action.');
            }

            return $this->redirect('/admin/users?user=' . rawurlencode($userId->value()));
        }

        if ($this->section === AdminCommunitySection::Access) {
            if ($action === 'save_group') {
                $id = $this->community->saveGroup(
                    $actor,
                    self::optionalString($body, 'group_id', 191),
                    self::requiredString($body, 'group_key', 64),
                    self::requiredString($body, 'name', 100),
                    self::integer($body, 'sort_order', 0, 65535, 0),
                    $requestId,
                    $now,
                );
                return $this->redirect('/admin/access#group-' . rawurlencode($id));
            }
            if ($action === 'save_role') {
                $id = $this->community->saveRole(
                    $actor,
                    self::optionalString($body, 'role_id', 191),
                    self::requiredString($body, 'role_key', 64),
                    self::requiredString($body, 'name', 100),
                    RoleKind::from(self::requiredString($body, 'kind', 16)),
                    self::integer($body, 'priority', 0, 65535, 0),
                    $requestId,
                    $now,
                );
                return $this->redirect('/admin/access?role=' . rawurlencode($id));
            }
            if ($action === 'save_appearance') {
                $roleId = self::requiredStoredId($body, 'role_id');
                $appearance = new RoleAppearance(
                    EntityId::fromString($roleId),
                    self::color(self::optionalString($body, 'text_color', 7)),
                    self::color(self::optionalString($body, 'gradient_from', 7)),
                    self::color(self::optionalString($body, 'gradient_to', 7)),
                    self::integer($body, 'gradient_angle', 0, 360, 90),
                    self::enumOrNull(RoleAppearanceIcon::class, self::optionalString($body, 'icon', 32)),
                    self::optionalString($body, 'banner_text', 64),
                    self::color(self::optionalString($body, 'banner_color', 7)),
                    RoleAppearancePattern::from(self::requiredString($body, 'pattern', 32)),
                    RoleAppearanceAnimation::from(self::requiredString($body, 'animation', 32)),
                    self::checked($body, 'show_mobile'),
                    self::checked($body, 'show_profile'),
                    self::checked($body, 'show_posts'),
                );
                $this->community->saveRoleAppearance($actor, $appearance, $requestId, $now);
                return $this->redirect('/admin/access?role=' . rawurlencode($roleId));
            }

            throw new InvalidArgumentException('Unknown ACP access action.');
        }

        if ($this->section === AdminCommunitySection::Forums && $action === 'save_node') {
            $node = self::forumNodeFromBody($body);
            $this->community->saveForumNode($actor, $node, $requestId, $now);

            return $this->redirect('/admin/forums?node=' . rawurlencode($node->id()->value()));
        }

        throw new InvalidArgumentException('This ACP section has no supported mutation.');
    }

    /** @param array<string,mixed> $body */
    private static function forumNodeFromBody(array $body): ForumNode
    {
        $rawId = self::optionalString($body, 'node_id', 32);
        $id = $rawId === null
            ? EntityId::fromString(bin2hex(random_bytes(16)))
            : self::hexId($rawId);
        $parent = self::optionalString($body, 'parent_id', 32);
        $parentId = $parent === null ? null : self::hexId($parent);
        $type = ForumNodeType::from(self::requiredString($body, 'node_type', 16));
        $title = self::requiredString($body, 'title', 150);
        $slug = ForumNodeSlug::fromString(self::requiredString($body, 'slug', 100));
        $description = self::optionalString($body, 'description', 500) ?? '';
        $sort = self::integer($body, 'sort_order', 0, 4294967295, 0);
        $visibility = ForumNodeVisibility::from(self::requiredString($body, 'visibility', 16));

        return match ($type) {
            ForumNodeType::Category => ForumNode::category(
                $id,
                $parentId,
                $title,
                $slug,
                $description,
                $sort,
                $visibility,
            ),
            ForumNodeType::Forum => ForumNode::forum(
                $id,
                $parentId,
                $title,
                $slug,
                new ForumSettings(
                    self::checked($body, 'allow_new_threads'),
                    self::checked($body, 'allow_replies'),
                    self::checked($body, 'require_thread_approval'),
                    self::checked($body, 'require_post_approval'),
                    ForumDefaultThreadSort::from(self::requiredString($body, 'default_thread_sort', 24)),
                    self::integer($body, 'threads_per_page', 5, 100, 20),
                ),
                $description,
                $sort,
                $visibility,
            ),
            ForumNodeType::Page => ForumNode::page(
                $id,
                $parentId,
                $title,
                $slug,
                self::optionalString($body, 'page_content', 65535) ?? '',
                $description,
                $sort,
                $visibility,
            ),
            ForumNodeType::Link => ForumNode::link(
                $id,
                $parentId,
                $title,
                $slug,
                ForumNodeLinkTarget::fromString(self::requiredString($body, 'link_target', 2048)),
                self::checked($body, 'link_new_window'),
                $description,
                $sort,
                $visibility,
            ),
        };
    }

    private function redirect(string $path): Response
    {
        return Response::redirect($this->basePath->prepend($path), 303)
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $query */
    private static function queryString(array $query, string $key, int $max): string
    {
        $value = $query[$key] ?? '';
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('ACP query value is invalid.');
        }

        return $value;
    }

    private static function optionalHexId(mixed $value): ?EntityId
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('ACP id is invalid.');
        }

        return self::hexId($value);
    }

    private static function hexId(string $value): EntityId
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $value) !== 1) {
            throw new InvalidArgumentException('ACP id is invalid.');
        }

        return EntityId::fromString($value);
    }

    /** @param array<string,mixed> $body */
    private static function requiredHexId(array $body, string $key): EntityId
    {
        return self::hexId(self::requiredString($body, $key, 32));
    }

    private static function optionalStoredId(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > 191
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('ACP stored id is invalid.');
        }

        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function requiredStoredId(array $body, string $key): string
    {
        $value = self::requiredString($body, $key, 191);
        return self::optionalStoredId($value)
            ?? throw new InvalidArgumentException('ACP stored id is invalid.');
    }

    /** @param array<string,mixed> $body */
    private static function requiredString(array $body, string $key, int $max): string
    {
        $value = $body[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('ACP field is invalid: ' . $key);
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('ACP field is invalid: ' . $key);
        }

        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function optionalString(array $body, string $key, int $max): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || strlen($value) > $max || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('ACP optional field is invalid: ' . $key);
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param array<string,mixed> $body */
    private static function integer(array $body, string $key, int $min, int $max, int $default): int
    {
        if (!array_key_exists($key, $body) || $body[$key] === '') {
            return $default;
        }
        $value = filter_var($body[$key], FILTER_VALIDATE_INT);
        if (!is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('ACP integer field is invalid: ' . $key);
        }

        return $value;
    }

    /** @param array<string,mixed> $body */
    private static function checked(array $body, string $key): bool
    {
        return ($body[$key] ?? null) === '1'
            || ($body[$key] ?? null) === 1
            || ($body[$key] ?? null) === true;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, int $maxItems, int $maxLength): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value) || count($value) > $maxItems) {
            throw new InvalidArgumentException('ACP multi-value field is invalid.');
        }
        $result = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || $entry === '' || strlen($entry) > $maxLength) {
                throw new InvalidArgumentException('ACP multi-value entry is invalid.');
            }
            $result[] = $entry;
        }

        return $result;
    }

    private static function color(?string $value): ?RoleColor
    {
        return $value === null ? null : RoleColor::fromHex($value);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    private static function enumOrNull(string $enum, ?string $value): ?\BackedEnum
    {
        if ($value === null) {
            return null;
        }
        $result = $enum::tryFrom($value);
        if (!$result instanceof $enum) {
            throw new InvalidArgumentException('ACP enum value is invalid.');
        }

        return $result;
    }
}
