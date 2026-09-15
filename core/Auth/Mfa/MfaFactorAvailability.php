<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class MfaFactorAvailability
{
    public function __construct(private QueryExecutor $database)
    {
    }

    /** @return list<MfaMethod> */
    public function methods(EntityId $userId): array
    {
        $methods = [];
        if ((int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_totp_factors` WHERE `user_id` = :user_id AND `enabled_at_utc` IS NOT NULL',
            ['user_id' => $userId->value()],
        )) > 0) {
            $methods[] = MfaMethod::Totp;
        }
        if ((int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_recovery_codes` WHERE `user_id` = :user_id AND `used_at_utc` IS NULL',
            ['user_id' => $userId->value()],
        )) > 0) {
            $methods[] = MfaMethod::RecoveryCode;
        }
        if ((int) $this->database->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_passkey_credentials` WHERE `user_id` = :user_id AND `revoked_at_utc` IS NULL',
            ['user_id' => $userId->value()],
        )) > 0) {
            $methods[] = MfaMethod::Passkey;
        }
        return $methods;
    }
}
