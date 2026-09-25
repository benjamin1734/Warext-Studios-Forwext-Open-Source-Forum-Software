<?php

declare(strict_types=1);

namespace Forwext\Core\Admin\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Audit\AuditAction;
use Forwext\Core\Audit\AuditEvent;
use Forwext\Core\Audit\AuditRecorder;
use Forwext\Core\Audit\AuditRequestId;
use Forwext\Core\Audit\AuditScope;
use Forwext\Core\Capability\CapabilityResolver;
use Forwext\Core\Config\ConfigRepository;
use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionDeniedException;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Security\Secret\SecretStore;
use SensitiveParameter;

final readonly class SystemIntegrationService
{
    private const ACP_PERMISSION = 'acp.access';
    private const MANAGE_PERMISSION = 'integration.manage';

    public function __construct(
        private SystemIntegrationCatalog $catalog,
        private ConfigRepository $config,
        private GeneratedConfigStore $generated,
        private SecretStore $secrets,
        private PermissionAuthorizer $authorizer,
        private AuditRecorder $audit,
        private CapabilityResolver $capabilities = new CapabilityResolver(),
    ) {
    }

    public function snapshot(EntityId $actor): SystemIntegrationSnapshot
    {
        $this->requireManage($actor);
        $values = [];
        $environment = [];
        foreach ($this->catalog->settings() as $definition) {
            $values[$definition->key] = $this->config->get($definition->configPath);
            $environment[$definition->key] = getenv(self::environmentName($definition->configPath)) !== false;
        }

        $secretConfigured = [];
        foreach ($this->catalog->secrets() as $definition) {
            $secretConfigured[$definition->key] = $this->secrets->has($definition->secretName);
        }

        return new SystemIntegrationSnapshot(
            $values,
            $environment,
            $secretConfigured,
            $this->capabilities->resolve()->all(),
        );
    }

    public function saveSetting(
        EntityId $actor,
        string $key,
        mixed $rawValue,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->catalog->setting($key);
        $value = $definition->normalize($rawValue);
        $before = $this->config->get($definition->configPath);

        $event = $this->event(
            $actor,
            'integration.setting.update',
            $key,
            ['configured_value'=>$before],
            ['configured_value'=>$value, 'environment_override'=>getenv(self::environmentName($definition->configPath)) !== false],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, fn (): mixed => $this->generated->set($definition->configPath, $value));
    }

    public function resetSetting(
        EntityId $actor,
        string $key,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->catalog->setting($key);
        $before = $this->config->get($definition->configPath);
        $event = $this->event(
            $actor,
            'integration.setting.reset',
            $key,
            ['configured_value'=>$before],
            ['generated_override_removed'=>true],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, fn (): mixed => $this->generated->delete($definition->configPath));
    }

    public function setSecret(
        EntityId $actor,
        string $key,
        #[SensitiveParameter] mixed $rawValue,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->catalog->secret($key);
        $value = $definition->normalize($rawValue);
        $before = $this->secrets->has($definition->secretName);
        $event = $this->event(
            $actor,
            'integration.secret.update',
            $key,
            ['configured'=>$before],
            ['configured'=>true],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, function () use ($definition, $value): void {
            $this->secrets->set($definition->secretName, $value);
        });
    }

    public function deleteSecret(
        EntityId $actor,
        string $key,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): void {
        $this->requireManage($actor);
        $definition = $this->catalog->secret($key);
        $before = $this->secrets->has($definition->secretName);
        $event = $this->event(
            $actor,
            'integration.secret.delete',
            $key,
            ['configured'=>$before],
            ['configured'=>false],
            $requestId,
            $at,
        );
        $this->audit->mutate($event, fn (): bool => $this->secrets->delete($definition->secretName));
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

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function event(
        EntityId $actor,
        string $action,
        string $targetId,
        array $before,
        array $after,
        AuditRequestId $requestId,
        DateTimeImmutable $at,
    ): AuditEvent {
        return new AuditEvent(
            AuditEvent::generateId(),
            AuditScope::Administration,
            $actor,
            AuditAction::fromString($action),
            'integration.setting',
            $targetId,
            null,
            $action,
            $requestId,
            $before,
            $after,
            $at->setTimezone(new DateTimeZone('UTC')),
        );
    }

    private static function environmentName(string $configPath): string
    {
        return 'FORWEXT_CONFIG__' . strtoupper(str_replace('.', '__', $configPath));
    }
}
