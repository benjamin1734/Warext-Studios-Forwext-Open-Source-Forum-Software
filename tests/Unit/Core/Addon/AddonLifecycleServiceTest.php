<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Addon;

use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Addon\AddonDataPurger;
use Forwext\Core\Addon\AddonDataRetentionPolicy;
use Forwext\Core\Addon\AddonDataState;
use Forwext\Core\Addon\AddonDependencyResolver;
use Forwext\Core\Addon\AddonId;
use Forwext\Core\Addon\AddonInstallation;
use Forwext\Core\Addon\AddonLifecycleService;
use Forwext\Core\Addon\AddonManifest;
use Forwext\Core\Addon\AddonPackage;
use Forwext\Core\Addon\AddonRepository;
use Forwext\Core\Addon\AddonState;
use Forwext\Core\Addon\AddonUninstallMode;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
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
use Forwext\Core\Migration\SemanticVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AddonLifecycleServiceTest extends TestCase
{
    public function testReinstallPreservesPurgedStateAndRequiresImmutablePackageChecksum(): void
    {
        $actor = EntityId::fromString(str_repeat('a', 32));
        $manifest = self::manifest('Acme/Demo', '1.0.0', '1.0.0', AddonDataRetentionPolicy::PurgeSupported);
        $recorded = new AddonInstallation($manifest, AddonState::Uninstalled, AddonDataState::Purged, str_repeat('1', 64));

        $repository = self::repository(['Acme/Demo'=>$recorded]);
        self::service($actor, $repository)->install(
            $actor,
            self::package($manifest, str_repeat('1', 64)),
            AuditRequestId::fromString('reinstall-preserve'),
            self::now(),
        );

        $saved = $repository->find(AddonId::fromString('Acme/Demo'));
        self::assertInstanceOf(AddonInstallation::class, $saved);
        self::assertSame(AddonState::Disabled, $saved->state);
        self::assertSame(AddonDataState::Purged, $saved->dataState);

        $repository = self::repository(['Acme/Demo'=>$recorded]);

        $this->expectException(InvalidArgumentException::class);
        self::service($actor, $repository)->install(
            $actor,
            self::package($manifest, str_repeat('2', 64)),
            AuditRequestId::fromString('reinstall-checksum'),
            self::now(),
        );
    }

    public function testEnableRevalidatesCurrentForwextCompatibility(): void
    {
        $actor = EntityId::fromString(str_repeat('a', 32));
        $manifest = self::manifest('Acme/Demo', '1.0.0', '2.0.0');
        $repository = self::repository([
            'Acme/Demo'=>new AddonInstallation(
                $manifest,
                AddonState::Disabled,
                AddonDataState::Retained,
                str_repeat('3', 64),
            ),
        ]);

        $this->expectException(InvalidArgumentException::class);
        self::service($actor, $repository, forwextVersion:SemanticVersion::parse('1.0.0'))->enable(
            $actor,
            AddonId::fromString('Acme/Demo'),
            AuditRequestId::fromString('enable-version-check'),
            self::now(),
        );
    }

    public function testDeleteDataPurgeRunsInsideAuditMutationBeforeLifecycleSave(): void
    {
        $actor = EntityId::fromString(str_repeat('a', 32));
        $manifest = self::manifest('Acme/Demo', '1.0.0', '1.0.0', AddonDataRetentionPolicy::PurgeSupported);
        /** @var ArrayObject<int,string> $sequence */
        $sequence = new ArrayObject();

        $repository = self::repository([
            'Acme/Demo'=>new AddonInstallation(
                $manifest,
                AddonState::Disabled,
                AddonDataState::Retained,
                str_repeat('4', 64),
            ),
        ], $sequence);

        self::service(
            $actor,
            $repository,
            self::purger($sequence),
            self::audit($sequence),
        )->uninstall(
            $actor,
            AddonId::fromString('Acme/Demo'),
            AddonUninstallMode::DeleteData,
            AuditRequestId::fromString('purge-transaction'),
            self::now(),
        );

        self::assertSame(['audit.begin', 'purge', 'save', 'audit.append'], $sequence->getArrayCopy());
        $saved = $repository->find(AddonId::fromString('Acme/Demo'));
        self::assertInstanceOf(AddonInstallation::class, $saved);
        self::assertSame(AddonState::Uninstalled, $saved->state);
        self::assertSame(AddonDataState::Purged, $saved->dataState);
    }

    /**
     * @param array<string,AddonInstallation> $initial
     * @param ArrayObject<int,string>|null $sequence
     */
    private static function repository(array $initial, ?ArrayObject $sequence = null): AddonRepository
    {
        return new class($initial, $sequence) implements AddonRepository {
            /** @param array<string,AddonInstallation> $items @param ArrayObject<int,string>|null $sequence */
            public function __construct(
                private array $items,
                private readonly ?ArrayObject $sequence,
            ) {
            }

            public function find(AddonId $id): ?AddonInstallation
            {
                return $this->items[$id->value()] ?? null;
            }

            public function all(): array
            {
                return $this->items;
            }

            public function save(AddonInstallation $installation, EntityId $actor, DateTimeImmutable $at): void
            {
                $this->sequence?->append('save');
                $this->items[$installation->manifest->id->value()] = $installation;
            }
        };
    }

    /** @param ArrayObject<int,string>|null $sequence */
    private static function purger(?ArrayObject $sequence = null): AddonDataPurger
    {
        return new class($sequence) implements AddonDataPurger {
            /** @param ArrayObject<int,string>|null $sequence */
            public function __construct(private readonly ?ArrayObject $sequence)
            {
            }

            public function supports(AddonId $id): bool
            {
                return true;
            }

            public function purge(AddonInstallation $installation): void
            {
                $this->sequence?->append('purge');
            }
        };
    }

    /** @param ArrayObject<int,string>|null $sequence */
    private static function audit(?ArrayObject $sequence = null): AuditRecorder
    {
        return new class($sequence) implements AuditRecorder {
            /** @param ArrayObject<int,string>|null $sequence */
            public function __construct(private readonly ?ArrayObject $sequence)
            {
            }

            public function append(AuditEvent $event): void
            {
                $this->sequence?->append('audit.append');
            }

            public function mutate(AuditEvent $event, callable $mutation): mixed
            {
                $this->sequence?->append('audit.begin');
                $result = $mutation();
                $this->append($event);

                return $result;
            }
        };
    }

    private static function service(
        EntityId $actor,
        AddonRepository $repository,
        ?AddonDataPurger $purger = null,
        ?AuditRecorder $audit = null,
        ?SemanticVersion $forwextVersion = null,
    ): AddonLifecycleService {
        $rules = new class implements PermissionRuleRepository {
            public function definition(PermissionKey $key): ?PermissionDefinition
            {
                return new PermissionDefinition($key, PermissionValueType::Flag);
            }

            public function rules(PermissionKey $key, UserAccessAssignment $assignment, ?EntityId $nodeId): array
            {
                return [
                    new PermissionRule(
                        PermissionSubjectType::User,
                        $assignment->userId(),
                        PermissionEffect::Allow,
                    ),
                ];
            }
        };
        $assignments = new class($actor) implements UserAccessAssignmentProvider {
            public function __construct(private readonly EntityId $actor)
            {
            }

            public function find(EntityId $userId): ?UserAccessAssignment
            {
                if (!$userId->equals($this->actor)) {
                    return null;
                }

                return new UserAccessAssignment(
                    $this->actor,
                    EntityId::fromString(str_repeat('f', 32)),
                );
            }
        };

        return new AddonLifecycleService(
            $repository,
            new AddonDependencyResolver(),
            $purger ?? self::purger(),
            new PermissionAuthorizer(new PermissionEngine($rules), $assignments),
            $audit ?? self::audit(),
            $forwextVersion ?? SemanticVersion::parse('1.0.0'),
        );
    }

    private static function package(AddonManifest $manifest, string $checksum): AddonPackage
    {
        return new AddonPackage(
            $manifest,
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'Acme' . DIRECTORY_SEPARATOR . 'Demo',
            $checksum,
        );
    }

    private static function manifest(
        string $id,
        string $version,
        string $forwext,
        AddonDataRetentionPolicy $retention = AddonDataRetentionPolicy::RetainOnly,
    ): AddonManifest {
        return AddonManifest::fromJson((string) json_encode([
            'id'=>$id,
            'version'=>$version,
            'title'=>$id,
            'description'=>'Lifecycle test fixture.',
            'requires'=>['forwext'=>$forwext,'addons'=>[]],
            'conflicts'=>['addons'=>[]],
            'data_retention'=>$retention->value,
        ], JSON_THROW_ON_ERROR));
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-25 09:30:00', new DateTimeZone('UTC'));
    }
}
