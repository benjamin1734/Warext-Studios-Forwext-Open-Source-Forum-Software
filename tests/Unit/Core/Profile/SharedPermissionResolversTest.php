<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Profile;

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
use Forwext\Core\Profile\Music\EngineProfileMusicPermissionResolver;
use Forwext\Core\Profile\Music\ProfileMusicPermission;
use Forwext\Core\Profile\Url\EngineProfileUrlPermissionResolver;
use Forwext\Core\Profile\Url\ProfileUrlPermission;
use PHPUnit\Framework\TestCase;

final class SharedPermissionResolversTest extends TestCase
{
    public function testProfileBridgesMapToSharedPermissionKeys(): void
    {
        $userId = EntityId::fromString('dddddddddddddddddddddddddddddddd');
        $assignment = new UserAccessAssignment($userId, EntityId::fromString('group:member'));
        $repository = new ProfileBridgePermissionRepository();
        $repository->effects = [
            'profile.custom_url.use' => PermissionEffect::Allow,
            'profile.music.use' => PermissionEffect::Allow,
            'profile.music.upload' => PermissionEffect::Allow,
            'profile.music.external' => PermissionEffect::Deny,
            'profile.music.autoplay' => PermissionEffect::Allow,
            'profile.music.moderate' => PermissionEffect::Deny,
        ];
        $authorizer = new PermissionAuthorizer(
            new PermissionEngine($repository),
            new ProfileBridgeAssignmentProvider($assignment),
        );

        $music = new EngineProfileMusicPermissionResolver($authorizer);
        $url = new EngineProfileUrlPermissionResolver($authorizer);

        self::assertTrue($url->allows($userId, ProfileUrlPermission::Use));
        self::assertTrue($music->allows($userId, ProfileMusicPermission::Use));
        self::assertTrue($music->allows($userId, ProfileMusicPermission::Upload));
        self::assertFalse($music->allows($userId, ProfileMusicPermission::External));
        self::assertTrue($music->allows($userId, ProfileMusicPermission::Autoplay));
        self::assertFalse($music->allows($userId, ProfileMusicPermission::Moderate));
        self::assertSame([
            'profile.custom_url.use',
            'profile.music.use',
            'profile.music.upload',
            'profile.music.external',
            'profile.music.autoplay',
            'profile.music.moderate',
        ], $repository->requestedKeys);
    }
}

final readonly class ProfileBridgeAssignmentProvider implements UserAccessAssignmentProvider
{
    public function __construct(private UserAccessAssignment $assignment)
    {
    }

    public function find(EntityId $userId): ?UserAccessAssignment
    {
        return $this->assignment;
    }
}

final class ProfileBridgePermissionRepository implements PermissionRuleRepository
{
    /** @var array<string, PermissionEffect> */
    public array $effects = [];

    /** @var list<string> */
    public array $requestedKeys = [];

    public function definition(PermissionKey $key): ?PermissionDefinition
    {
        return new PermissionDefinition($key, PermissionValueType::Flag);
    }

    public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
    {
        $this->requestedKeys[] = $key->value();
        $effect = $this->effects[$key->value()] ?? PermissionEffect::Deny;

        return [new PermissionRule(
            PermissionSubjectType::Group,
            $assignment->primaryGroupId(),
            $effect,
        )];
    }
}
