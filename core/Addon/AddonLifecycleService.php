<?php

declare(strict_types=1);

namespace Forwext\Core\Addon;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Migration\SemanticVersion;
use InvalidArgumentException;

final readonly class AddonLifecycleService
{
    private const ACP_PERMISSION = 'acp.access';
    private const MANAGE_PERMISSION = 'addon.manage';

    public function __construct(
        private AddonRepository $repository,
        private AddonDependencyResolver $resolver,
        private AddonDataPurger $dataPurger,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private SemanticVersion $forwextVersion,
    ) {
    }

    public function install(
        EntityId $actor,
        AddonPackage $package,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $existing = $this->repository->find($package->manifest->id);
        if ($existing !== null && $existing->state !== AddonState::Uninstalled) {
            throw new InvalidArgumentException('Add-on is already installed.');
        }
        if ($existing !== null && !$existing->manifest->version->equals($package->manifest->version)) {
            throw new InvalidArgumentException('Reinstall must use the recorded version; use upgrade for version changes.');
        }

        $all = $this->repository->all();
        $this->resolver->assertPackageCompatible($package->manifest, $all, $this->forwextVersion);
        $after = new AddonInstallation(
            $package->manifest,
            AddonState::Disabled,
            AddonDataState::Retained,
            $package->checksum,
        );
        $this->persist($actor, 'addon.install', $existing, $after, $requestId, $at);
    }

    public function enable(
        EntityId $actor,
        AddonId $id,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $all = $this->repository->all();
        $current = $all[$id->value()] ?? null;
        if (!$current instanceof AddonInstallation) {
            throw new InvalidArgumentException('Add-on is not installed.');
        }
        $this->resolver->assertCanEnable($id, $all);
        $after = new AddonInstallation(
            $current->manifest,
            AddonState::Enabled,
            $current->dataState,
            $current->packageChecksum,
        );
        $this->persist($actor, 'addon.enable', $current, $after, $requestId, $at);
    }

    public function disable(
        EntityId $actor,
        AddonId $id,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $all = $this->repository->all();
        $current = $all[$id->value()] ?? null;
        if (!$current instanceof AddonInstallation || $current->state !== AddonState::Enabled) {
            throw new InvalidArgumentException('Only an enabled add-on can be disabled.');
        }
        $this->resolver->assertCanDisable($id, $all);
        $after = new AddonInstallation(
            $current->manifest,
            AddonState::Disabled,
            $current->dataState,
            $current->packageChecksum,
        );
        $this->persist($actor, 'addon.disable', $current, $after, $requestId, $at);
    }

    public function upgrade(
        EntityId $actor,
        AddonPackage $package,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $current = $this->repository->find($package->manifest->id);
        if (!$current instanceof AddonInstallation || $current->state !== AddonState::Disabled) {
            throw new InvalidArgumentException('Add-on must be installed and disabled before upgrade.');
        }
        if (!$package->manifest->version->isGreaterThan($current->manifest->version)) {
            throw new InvalidArgumentException('Add-on upgrade version must be newer than the installed version.');
        }

        $all = $this->repository->all();
        $this->resolver->assertPackageCompatible($package->manifest, $all, $this->forwextVersion);
        $after = new AddonInstallation(
            $package->manifest,
            AddonState::Disabled,
            $current->dataState,
            $package->checksum,
        );
        $this->persist($actor, 'addon.upgrade', $current, $after, $requestId, $at);
    }

    public function uninstall(
        EntityId $actor,
        AddonId $id,
        AddonUninstallMode $mode,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $all = $this->repository->all();
        $current = $all[$id->value()] ?? null;
        if (!$current instanceof AddonInstallation || $current->state !== AddonState::Disabled) {
            throw new InvalidArgumentException('Add-on must be disabled before uninstall.');
        }
        $this->resolver->assertCanUninstall($id, $all);

        $dataState = AddonDataState::Retained;
        if ($mode === AddonUninstallMode::DeleteData) {
            if ($current->manifest->dataRetention !== AddonDataRetentionPolicy::PurgeSupported) {
                throw new InvalidArgumentException('This add-on manifest does not support data purge.');
            }
            if (!$this->dataPurger->supports($id)) {
                throw new InvalidArgumentException('No safe data purger is registered for this add-on.');
            }
            $this->dataPurger->purge($current);
            $dataState = AddonDataState::Purged;
        }

        $after = new AddonInstallation(
            $current->manifest,
            AddonState::Uninstalled,
            $dataState,
            $current->packageChecksum,
        );
        $this->persist(
            $actor,
            $mode === AddonUninstallMode::DeleteData ? 'addon.uninstall.delete_data' : 'addon.uninstall.keep_data',
            $current,
            $after,
            $requestId,
            $at,
        );
    }

    private function persist(
        EntityId $actor,
        string $action,
        ?AddonInstallation $before,
        AddonInstallation $after,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $event = new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            'third_party_addon',
            $after->manifest->id->auditKey(),
            null,
            $action,
            $requestId,
            $before?->auditSnapshot() ?? [],
            $after->auditSnapshot(),
            $at->setTimezone(new DateTimeZone('UTC')),
        );
        $this->audit->mutate(
            $event,
            fn (): mixed => $this->repository->save($after, $actor, $at),
        );
    }

    private function requireManage(EntityId $actor): void
    {
        foreach ([self::ACP_PERMISSION, self::MANAGE_PERMISSION] as $permission) {
            $decision = $this->authorizer->resolve($actor, PermissionKey::fromString($permission));
            if (!$decision->isAllowed()) {
                throw new PermissionDeniedException($decision);
            }
        }
    }
}
