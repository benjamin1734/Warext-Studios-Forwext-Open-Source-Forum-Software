<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Ui;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDefinition;
use Forwext\Core\Domain\Access\Permission\PermissionEffect;
use Forwext\Core\Domain\Access\Permission\PermissionEngine;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Access\Permission\PermissionRule;
use Forwext\Core\Domain\Access\Permission\PermissionRuleRepository;
use Forwext\Core\Domain\Access\Permission\PermissionSubjectType;
use Forwext\Core\Domain\Access\Permission\PermissionValueType;
use Forwext\Core\Domain\Access\UserAccessAssignment;
use Forwext\Core\Domain\Access\UserAccessAssignmentProvider;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideLevel;
use Forwext\Core\Ui\Appearance\Guide\AppearanceGuideService;
use Forwext\Core\Ui\Appearance\Guide\AppearancePreviewDevice;
use PHPUnit\Framework\TestCase;

final class AppearanceGuideServiceTest extends TestCase
{
    public function testBasicModeUsesSafeDefaultPresetAndHidesAdvancedItems(): void
    {
        $actor = EntityId::fromString('user:appearance-guide');
        $service = new AppearanceGuideService($this->authorizer($actor, false));

        $snapshot = $service->snapshot(
            $actor,
            AppearanceGuideLevel::Basic,
            AppearancePreviewDevice::Desktop,
            '',
            'balanced',
        );

        self::assertSame('balanced', $snapshot->selectedPreset->key);
        self::assertFalse($snapshot->advancedAllowed);
        self::assertNotEmpty($snapshot->items);
        foreach ($snapshot->items as $item) {
            self::assertSame(AppearanceGuideLevel::Basic, $item->level);
            self::assertTrue($snapshot->isAccessible($item));
        }
    }

    public function testAdvancedModeExplainsLockedAdvancedEntriesWithoutGrantingAccess(): void
    {
        $actor = EntityId::fromString('user:appearance-guide');
        $service = new AppearanceGuideService($this->authorizer($actor, false));

        $snapshot = $service->snapshot(
            $actor,
            AppearanceGuideLevel::Advanced,
            AppearancePreviewDevice::Mobile,
            'publish',
            'compact',
        );

        self::assertSame('compact', $snapshot->selectedPreset->key);
        self::assertSame(AppearancePreviewDevice::Mobile, $snapshot->device);
        self::assertNotEmpty($snapshot->items);
        self::assertContains(false, array_values($snapshot->itemAccess), true);
    }

    public function testAdvancedPermissionUnlocksAdvancedSearchResult(): void
    {
        $actor = EntityId::fromString('user:appearance-guide');
        $service = new AppearanceGuideService($this->authorizer($actor, true));

        $snapshot = $service->snapshot(
            $actor,
            AppearanceGuideLevel::Advanced,
            AppearancePreviewDevice::Tablet,
            'custom css',
            'showcase',
        );

        self::assertTrue($snapshot->advancedAllowed);
        self::assertCount(1, $snapshot->items);
        self::assertTrue($snapshot->isAccessible($snapshot->items[0]));
        self::assertSame('appearance.theme.revisions', $snapshot->items[0]->key);
    }

    private function authorizer(EntityId $actor, bool $advanced): PermissionAuthorizer
    {
        $group = EntityId::fromString('group:member');
        $assignment = new UserAccessAssignment($actor, $group);
        $definitions = [];
        $rules = [];

        foreach (['appearance.manage', 'appearance.advanced'] as $permission) {
            $key = PermissionKey::fromString($permission);
            $definitions[$permission] = new PermissionDefinition($key, PermissionValueType::Flag);
            if ($permission === 'appearance.manage' || $advanced) {
                $rules[$permission] = [
                    new PermissionRule(
                        PermissionSubjectType::User,
                        $actor,
                        PermissionEffect::Allow,
                    ),
                ];
            } else {
                $rules[$permission] = [];
            }
        }

        $repository = new AppearanceGuidePermissionRepository($definitions, $rules);
        $assignments = new AppearanceGuideAssignmentProvider($assignment);

        return new PermissionAuthorizer(new PermissionEngine($repository), $assignments);
    }
}

/** @internal */
final readonly class AppearanceGuidePermissionRepository implements PermissionRuleRepository
{
    /**
     * @param array<string,PermissionDefinition> $definitions
     * @param array<string,list<PermissionRule>> $rules
     */
    public function __construct(
        private array $definitions,
        private array $rules,
    ) {
    }

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return $this->definitions[$key->value()] ?? null;
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        return $this->rules[$key->value()] ?? [];
    }
}

/** @internal */
final readonly class AppearanceGuideAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment->userId()->equals($userId) ? $this->assignment : null;
    }
}
