<?php

declare(strict_types=1);

namespace Forwext\Database\Migrations\Core;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Domain\Access\DatabaseUserAccessAssignmentProvider;
use Forwext\Core\Domain\Access\Permission\FirstPartyPermissionCatalog;
use Forwext\Core\Migration\Migration;
use Forwext\Core\Migration\MigrationContext;
use Forwext\Core\Migration\MigrationId;
use Forwext\Core\Migration\MigrationOwner;
use Forwext\Core\Migration\MigrationVerification;

final readonly class RegisterFirstPartyPermissionNamespaces implements Migration
{
    private const PROFILE_PERMISSIONS = [
        'profile.custom_url.use',
        'profile.music.use',
        'profile.music.upload',
        'profile.music.external',
        'profile.music.autoplay',
        'profile.music.moderate',
    ];

    public function id(): MigrationId
    {
        return MigrationId::fromString('20260915235900_permission_namespaces');
    }

    public function owner(): MigrationOwner
    {
        return MigrationOwner::core();
    }

    public function isIdempotent(): bool
    {
        return true;
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(MigrationContext $context): void
    {
        foreach (FirstPartyPermissionCatalog::entries() as $entry) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permissions` '
                . '(`permission_key`, `value_type`, `description`, `created_at_utc`, `updated_at_utc`) '
                . 'VALUES (:permission_key, :value_type, :description, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `value_type` = VALUES(`value_type`), '
                . '`description` = VALUES(`description`), `updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'permission_key' => $entry->key()->value(),
                    'value_type' => $entry->valueType()->value,
                    'description' => $entry->description(),
                ],
            ));
        }

        $compatibility = [
            'profile.custom_url.use' => 'allow',
            'profile.music.use' => 'allow',
            'profile.music.upload' => 'allow',
            'profile.music.external' => 'deny',
            'profile.music.autoplay' => 'allow',
            'profile.music.moderate' => 'deny',
        ];
        foreach ($compatibility as $permissionKey => $effect) {
            $context->execute(new CompiledQuery(
                'INSERT INTO `forwext_permission_global_rules` '
                . '(`subject_type`, `subject_id`, `permission_key`, `effect`, `numeric_limit`, `updated_at_utc`) '
                . 'VALUES (\'group\', :subject_id, :permission_key, :effect, NULL, UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = NULL, '
                . '`updated_at_utc` = VALUES(`updated_at_utc`)',
                [
                    'subject_id' => DatabaseUserAccessAssignmentProvider::UNASSIGNED_GROUP_ID,
                    'permission_key' => $permissionKey,
                    'effect' => $effect,
                ],
            ));
        }

        foreach (['new_user', 'member', 'verified', 'moderator', 'administrator'] as $templateKey) {
            foreach (self::PROFILE_PERMISSIONS as $permissionKey) {
                $effect = match ($permissionKey) {
                    'profile.music.external' => 'deny',
                    'profile.music.moderate' => in_array($templateKey, ['moderator', 'administrator'], true)
                        ? 'allow'
                        : 'deny',
                    default => 'allow',
                };
                $context->execute(new CompiledQuery(
                    'INSERT INTO `forwext_permission_template_rules` '
                    . '(`template_key`, `permission_key`, `effect`, `numeric_limit`) '
                    . 'VALUES (:template_key, :permission_key, :effect, NULL) '
                    . 'ON DUPLICATE KEY UPDATE `effect` = VALUES(`effect`), `numeric_limit` = NULL',
                    [
                        'template_key' => $templateKey,
                        'permission_key' => $permissionKey,
                        'effect' => $effect,
                    ],
                ));
            }
        }
    }

    public function verify(MigrationContext $context): MigrationVerification
    {
        $parameters = [];
        $placeholders = [];
        foreach (FirstPartyPermissionCatalog::entries() as $index => $entry) {
            $name = 'permission_' . $index;
            $placeholders[] = ':' . $name;
            $parameters[$name] = $entry->key()->value();
        }

        $catalogCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permissions` WHERE `permission_key` IN ('
            . implode(', ', $placeholders) . ')',
            $parameters,
        ));
        $compatibilityCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_global_rules` '
            . 'WHERE `subject_type` = \'group\' AND `subject_id` = :subject_id '
            . "AND `permission_key` LIKE 'profile.%'",
            ['subject_id' => DatabaseUserAccessAssignmentProvider::UNASSIGNED_GROUP_ID],
        ));
        $templateCount = $context->fetchValue(new CompiledQuery(
            'SELECT COUNT(*) FROM `forwext_permission_template_rules` '
            . "WHERE `template_key` IN ('new_user', 'member', 'verified', 'moderator', 'administrator') "
            . "AND `permission_key` LIKE 'profile.%'",
        ));

        return (int) $catalogCount === count(FirstPartyPermissionCatalog::entries())
            && (int) $compatibilityCount === 6
            && (int) $templateCount === 30
            ? MigrationVerification::passed()
            : MigrationVerification::failed('First-party permission namespaces or profile bridge defaults are incomplete.');
    }
}
