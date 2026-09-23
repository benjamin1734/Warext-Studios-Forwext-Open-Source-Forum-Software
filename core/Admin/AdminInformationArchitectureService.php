<?php

declare(strict_types=1);

namespace Forwext\Core\Admin;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Admin\Dashboard\AdminActionQueueService;
use Forwext\Core\Admin\Dashboard\AdminDashboardSnapshot;
use Forwext\Core\Admin\Navigation\AdminNavigationItem;
use Forwext\Core\Admin\Navigation\AdminNavigationPreferenceRepository;
use Forwext\Core\Admin\Navigation\AdminNavigationRegistry;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use InvalidArgumentException;

final readonly class AdminInformationArchitectureService
{
    public const ACCESS_PERMISSION = 'acp.access';

    public function __construct(
        private AdminNavigationRegistry $navigation,
        private AdminNavigationPreferenceRepository $preferences,
        private AdminActionQueueService $queues,
        private PermissionAuthorizer $authorizer,
    ) {
    }

    public function dashboard(EntityId $actor, string $search = ''): AdminDashboardSnapshot
    {
        $this->requireAccess($actor);
        $search = trim($search);
        if (strlen($search) > 80 || preg_match('//u', $search) !== 1) {
            throw new InvalidArgumentException('ACP search query is invalid.');
        }

        $visible = $this->navigation->visible($actor);
        $allowedKeys = [];
        $sections = [];
        foreach ($visible as $item) {
            $allowedKeys[$item->key] = true;
            $sections[$item->section->value] ??= [];
            $sections[$item->section->value][] = $item;
        }

        $preferences = $this->preferences->load($actor)->filtered($allowedKeys);

        return new AdminDashboardSnapshot(
            $search,
            $sections,
            $search === '' ? [] : $this->navigation->search($actor, $search),
            $this->resolvePreferenceItems($actor, $preferences->favorites()),
            $this->resolvePreferenceItems($actor, $preferences->recent()),
            $this->queues->forActor($actor),
        );
    }

    public function toggleFavorite(
        EntityId $actor,
        string $navigationKey,
        DateTimeImmutable $at,
    ): void {
        $this->requireAccess($actor);
        $item = $this->navigation->accessible($actor, $navigationKey);
        if (!$item instanceof AdminNavigationItem) {
            throw new InvalidArgumentException('ACP navigation item is unavailable.');
        }

        $visible = $this->navigation->visible($actor);
        $allowed = array_fill_keys(array_map(
            static fn (AdminNavigationItem $entry): string => $entry->key,
            $visible,
        ), true);
        $next = $this->preferences->load($actor)
            ->filtered($allowed)
            ->toggledFavorite($item->key);

        $this->preferences->save($actor, $next, $at->setTimezone(new DateTimeZone('UTC')));
    }

    public function targetAndRecordRecent(
        EntityId $actor,
        string $navigationKey,
        DateTimeImmutable $at,
    ): string {
        $this->requireAccess($actor);
        $item = $this->navigation->accessible($actor, $navigationKey);
        if (!$item instanceof AdminNavigationItem) {
            throw new InvalidArgumentException('ACP navigation item is unavailable.');
        }

        $visible = $this->navigation->visible($actor);
        $allowed = array_fill_keys(array_map(
            static fn (AdminNavigationItem $entry): string => $entry->key,
            $visible,
        ), true);
        $next = $this->preferences->load($actor)
            ->filtered($allowed)
            ->recordedRecent($item->key);
        $this->preferences->save($actor, $next, $at->setTimezone(new DateTimeZone('UTC')));

        return $item->path;
    }

    private function requireAccess(EntityId $actor): void
    {
        $decision = $this->authorizer->resolve($actor, PermissionKey::fromString(self::ACCESS_PERMISSION));
        if (!$decision->isAllowed()) {
            throw new PermissionDeniedException($decision);
        }
    }

    /**
     * @param list<string> $keys
     * @return list<AdminNavigationItem>
     */
    private function resolvePreferenceItems(EntityId $actor, array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $item = $this->navigation->accessible($actor, $key);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
