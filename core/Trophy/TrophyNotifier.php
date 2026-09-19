<?php

declare(strict_types=1);

namespace Forwext\Core\Trophy;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class TrophyNotifier
{
    public const AWARDED='trophy.awarded';
    public const REVOKED='trophy.revoked';

    public function __construct(private NotificationDispatcher $dispatcher){}

    public static function registerDefinitions(NotificationRegistry $registry):void
    {
        $registry->register(new NotificationDefinition(
            self::AWARDED,'trophy','Yeni başarım kazandınız','{{name}} adlı {{kind}} hesabınıza eklendi.'
        ));
        $registry->register(new NotificationDefinition(
            self::REVOKED,'trophy','Başarım kaydı güncellendi','{{name}} adlı {{kind}} kaydınız geri alındı.'
        ));
    }

    public function awarded(TrophyGrant $grant,TrophyDefinition $definition):void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $grant->userId,self::AWARDED,
            ['name'=>$definition->name,'kind'=>$definition->kind->value],
            null,
            'trophy-awarded:'.$grant->grantId->value().':'.$grant->awardedAt->format('YmdHis.u'),
            '/members',
            ['trophy_id'=>$definition->trophyId->value(),'grant_id'=>$grant->grantId->value()]
        ));
    }

    public function revoked(TrophyGrant $grant,?TrophyDefinition $definition):void
    {
        if($definition===null)return;
        $this->dispatcher->dispatch(new NotificationRequest(
            $grant->userId,self::REVOKED,
            ['name'=>$definition->name,'kind'=>$definition->kind->value],
            null,
            'trophy-revoked:'.$grant->grantId->value().':'.($grant->revokedAt?->format('YmdHis.u')??'unknown'),
            '/members',
            ['trophy_id'=>$definition->trophyId->value(),'grant_id'=>$grant->grantId->value()]
        ));
    }
}
