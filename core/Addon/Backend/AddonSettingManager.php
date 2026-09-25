<?php

declare(strict_types=1);

namespace Forwext\Core\Addon\Backend;

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
use InvalidArgumentException;

final readonly class AddonSettingManager
{
    private const ACP_PERMISSION = 'acp.access';
    private const MANAGE_PERMISSION = 'addon.manage';

    public function __construct(
        private AddonSettingStore $store,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
    ) {
    }

    public function effective(AddonBackendRegistration $registration, string $key): bool|int|string
    {
        $definition = $this->definition($registration, $key);
        return $this->store->resolve($registration->addonId, $definition);
    }

    public function save(
        EntityId $actor,
        AddonBackendRegistration $registration,
        string $key,
        mixed $value,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): bool|int|string {
        $this->requireManage($actor);
        $definition = $this->definition($registration, $key);
        $before = $this->store->resolve($registration->addonId, $definition);
        $normalized = $definition->normalize($value);

        $event = $this->event(
            $actor,
            'addon.setting.save',
            $registration,
            $definition,
            $before,
            $normalized,
            $requestId,
            $at,
        );

        return $this->audit->mutate(
            $event,
            fn (): bool|int|string => $this->store->save(
                $registration->addonId,
                $definition,
                $normalized,
                $actor,
                $at,
            ),
        );
    }

    public function reset(
        EntityId $actor,
        AddonBackendRegistration $registration,
        string $key,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): bool|int|string {
        $this->requireManage($actor);
        $definition = $this->definition($registration, $key);
        $before = $this->store->resolve($registration->addonId, $definition);
        $after = $definition->defaultValue;

        $event = $this->event(
            $actor,
            'addon.setting.reset',
            $registration,
            $definition,
            $before,
            $after,
            $requestId,
            $at,
        );
        $this->audit->mutate(
            $event,
            function () use ($registration, $definition): void {
                $this->store->reset($registration->addonId, $definition);
            },
        );

        return $after;
    }

    private function definition(AddonBackendRegistration $registration, string $key): AddonSettingDefinition
    {
        $registration->namespace()->assertOwned($key, 'Add-on setting');
        return $registration->settingDefinition($key)
            ?? throw new InvalidArgumentException('Unknown add-on setting: ' . $key);
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

    private function event(
        EntityId $actor,
        string $action,
        AddonBackendRegistration $registration,
        AddonSettingDefinition $definition,
        bool|int|string $before,
        bool|int|string $after,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            'third_party_addon_setting',
            $definition->key,
            null,
            $action,
            $requestId,
            [
                'addon_id'=>$registration->addonId->value(),
                'setting_key'=>$definition->key,
                'value'=>$before,
            ],
            [
                'addon_id'=>$registration->addonId->value(),
                'setting_key'=>$definition->key,
                'value'=>$after,
            ],
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
