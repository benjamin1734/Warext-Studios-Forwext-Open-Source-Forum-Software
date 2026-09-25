<?php

declare(strict_types=1);

namespace Forwext\Core\Webhook;

use Forwext\Core\Domain\Access\Permission\PermissionAuthorizer;
use Forwext\Core\Domain\Access\Permission\PermissionKey;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class WebhookManagementService
{
    private PermissionKey $managePermission;

    public function __construct(
        private WebhookPlatformService $platform,
        private PermissionAuthorizer $permissions,
    ){
        $this->managePermission=PermissionKey::fromString('webhook.manage');
    }

    public function create(
        EntityId $actorUserId,
        string $eventName,
        string $destinationUrl,
        int $maxAttempts=8,
    ):WebhookIssuedSubscription{
        $this->assertAllowed($actorUserId);
        return $this->platform->createSubscription(
            $eventName,$destinationUrl,$actorUserId->value(),$maxAttempts,
        );
    }

    public function rotateSecret(
        EntityId $actorUserId,
        string $subscriptionId,
        int $graceSeconds=86400,
    ):string{
        $this->assertAllowed($actorUserId);
        return $this->platform->rotateSecret($subscriptionId,$graceSeconds);
    }

    public function test(EntityId $actorUserId,string $subscriptionId):string
    {
        $this->assertAllowed($actorUserId);
        return $this->platform->testSubscription($subscriptionId);
    }

    private function assertAllowed(EntityId $actorUserId):void
    {
        if(!$this->permissions->allows($actorUserId,$this->managePermission)){
            throw new WebhookException('Webhook management permission denied.');
        }
    }
}
