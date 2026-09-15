<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Policy;

use Forwext\Core\Auth\Mfa\MfaException;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;

final readonly class DatabaseMfaPolicyResolver
{
    public function __construct(private QueryExecutor $database, private UserGroupProvider $groups, private MfaPolicy $defaultPolicy = new MfaPolicy())
    {
    }

    public function resolve(EntityId $userId): MfaPolicy
    {
        $policy = $this->defaultPolicy;
        foreach (array_values(array_unique($this->groups->groupsFor($userId))) as $groupKey) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $groupKey) !== 1) {
                throw new MfaException('User group key is invalid for MFA policy resolution.');
            }
            $row = $this->database->fetchOne(new CompiledQuery(
                'SELECT `login_required`, `sensitive_action_required`, `trusted_device_bypass` FROM `forwext_mfa_group_policies` WHERE `group_key` = :group_key LIMIT 1',
                ['group_key' => $groupKey],
            ));
            if ($row !== null) {
                $policy = $policy->mergeStrictest(new MfaPolicy(
                    (bool) ($row['login_required'] ?? false),
                    (bool) ($row['sensitive_action_required'] ?? true),
                    (bool) ($row['trusted_device_bypass'] ?? false),
                ));
            }
        }
        return $policy;
    }
}
