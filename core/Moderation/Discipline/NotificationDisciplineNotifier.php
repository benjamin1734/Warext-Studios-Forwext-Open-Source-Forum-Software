<?php

declare(strict_types=1);

namespace Forwext\Core\Moderation\Discipline;

use Forwext\Core\Notification\NotificationDefinition;
use Forwext\Core\Notification\NotificationDispatcher;
use Forwext\Core\Notification\NotificationRegistry;
use Forwext\Core\Notification\NotificationRequest;

final readonly class NotificationDisciplineNotifier implements DisciplineNotifier
{
    public const WARNING_TYPE = 'moderation.discipline.warning';
    public const RESTRICTION_TYPE = 'moderation.discipline.restriction';
    public const SUSPENSION_TYPE = 'moderation.discipline.suspension';
    public const BAN_TYPE = 'moderation.discipline.ban';
    public const REVOKED_TYPE = 'moderation.discipline.revoked';

    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    public static function registerDefinitions(NotificationRegistry $registry): void
    {
        $registry->register(new NotificationDefinition(
            self::WARNING_TYPE,
            'discipline',
            'Hesabınıza uyarı verildi',
            'Uyarı puanı: {{points}}. Neden kodu: {{reason}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::RESTRICTION_TYPE,
            'discipline',
            'Hesabınıza içerik kısıtlaması uygulandı',
            'Kısıtlama: {{restriction}}. Neden kodu: {{reason}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::SUSPENSION_TYPE,
            'discipline',
            'Hesabınız geçici olarak askıya alındı',
            'Askıya alma süresi: {{expiry}}. Neden kodu: {{reason}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::BAN_TYPE,
            'discipline',
            'Hesabınıza ban uygulandı',
            'Ban süresi: {{expiry}}. Neden kodu: {{reason}}.',
        ));
        $registry->register(new NotificationDefinition(
            self::REVOKED_TYPE,
            'discipline',
            'Disiplin işlemi kaldırıldı',
            '{{type}} işlemi kaldırıldı.',
        ));
    }

    public function issued(DisciplineAction $action): void
    {
        [$type, $variables] = match ($action->type) {
            DisciplineActionType::Warning => [
                self::WARNING_TYPE,
                ['points' => (string) $action->points, 'reason' => $action->reasonCode->value()],
            ],
            DisciplineActionType::Restriction => [
                self::RESTRICTION_TYPE,
                [
                    'restriction' => implode(', ', array_map(
                        static fn (DisciplineRestrictionKey $key): string => $key->label(),
                        $action->restrictions,
                    )),
                    'reason' => $action->reasonCode->value(),
                ],
            ],
            DisciplineActionType::Suspension => [
                self::SUSPENSION_TYPE,
                [
                    'expiry' => $action->expiresAt?->format('Y-m-d H:i') . ' UTC',
                    'reason' => $action->reasonCode->value(),
                ],
            ],
            DisciplineActionType::Ban => [
                self::BAN_TYPE,
                [
                    'expiry' => $action->expiresAt === null ? 'Kalıcı' : $action->expiresAt->format('Y-m-d H:i') . ' UTC',
                    'reason' => $action->reasonCode->value(),
                ],
            ],
        };

        $this->dispatcher->dispatch(new NotificationRequest(
            $action->userId,
            $type,
            $variables,
            null,
            'discipline-issued:' . $action->actionId->value(),
            '/account/discipline',
            [
                'action_id' => $action->actionId->value(),
                'action_type' => $action->type->value,
                'appeal_reference' => $action->appealReference(),
            ],
        ));
    }

    public function revoked(DisciplineAction $action): void
    {
        $this->dispatcher->dispatch(new NotificationRequest(
            $action->userId,
            self::REVOKED_TYPE,
            ['type' => $action->type->label()],
            null,
            'discipline-revoked:' . $action->actionId->value(),
            '/account/discipline',
            ['action_id' => $action->actionId->value(), 'action_type' => $action->type->value],
        ));
    }
}
