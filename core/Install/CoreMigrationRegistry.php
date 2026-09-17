<?php

declare(strict_types=1);

namespace Forwext\Core\Install;

use Forwext\Core\Migration\Migration;
use Forwext\Database\Migrations\Core\CreateAttachmentPipelineTables;
use Forwext\Database\Migrations\Core\CreateAuthenticationRuntimeTables;
use Forwext\Database\Migrations\Core\CreateContentModerationTables;
use Forwext\Database\Migrations\Core\CreateCustomProfileUrlTables;
use Forwext\Database\Migrations\Core\CreateDiscussionStateTables;
use Forwext\Database\Migrations\Core\CreateForumMetadataTables;
use Forwext\Database\Migrations\Core\CreateForumNodeTables;
use Forwext\Database\Migrations\Core\CreateInfrastructureDriverTables;
use Forwext\Database\Migrations\Core\CreateMfaDeviceSecurityTables;
use Forwext\Database\Migrations\Core\CreateNotificationAlertTables;
use Forwext\Database\Migrations\Core\CreateNotificationSoundTables;
use Forwext\Database\Migrations\Core\CreateOAuthConnectedAccountTables;
use Forwext\Database\Migrations\Core\CreatePermissionEngineTables;
use Forwext\Database\Migrations\Core\CreatePermissionTemplateTables;
use Forwext\Database\Migrations\Core\CreatePollTables;
use Forwext\Database\Migrations\Core\CreatePostDomainTables;
use Forwext\Database\Migrations\Core\CreateProfileActivityTables;
use Forwext\Database\Migrations\Core\CreateProfileMusicTables;
use Forwext\Database\Migrations\Core\CreateQueueSchedulerRealtimeTables;
use Forwext\Database\Migrations\Core\CreateRegistrationSecurityTables;
use Forwext\Database\Migrations\Core\CreateRoleAppearanceTable;
use Forwext\Database\Migrations\Core\CreateRoleGroupTables;
use Forwext\Database\Migrations\Core\CreateSearchIndexTables;
use Forwext\Database\Migrations\Core\CreateSearchIndexLifecycleTables;
use Forwext\Database\Migrations\Core\CreateSocialInteractionTables;
use Forwext\Database\Migrations\Core\CreateThreadDomainTables;
use Forwext\Database\Migrations\Core\CreateUserDomainTables;
use Forwext\Database\Migrations\Core\CreateUserProfileMediaTables;
use Forwext\Database\Migrations\Core\RegisterFirstPartyPermissionNamespaces;

final class CoreMigrationRegistry
{
    /** @return list<Migration> */
    public static function all(): array
    {
        return [
            new CreateInfrastructureDriverTables(),
            new CreateQueueSchedulerRealtimeTables(),
            new CreateSearchIndexTables(),
            new CreateUserDomainTables(),
            new CreateRegistrationSecurityTables(),
            new CreateAuthenticationRuntimeTables(),
            new CreateMfaDeviceSecurityTables(),
            new CreateOAuthConnectedAccountTables(),
            new CreateUserProfileMediaTables(),
            new CreateProfileMusicTables(),
            new CreateCustomProfileUrlTables(),
            new CreateRoleGroupTables(),
            new CreatePermissionEngineTables(),
            new CreatePermissionTemplateTables(),
            new CreateRoleAppearanceTable(),
            new RegisterFirstPartyPermissionNamespaces(),
            new CreateForumNodeTables(),
            new CreateThreadDomainTables(),
            new CreatePostDomainTables(),
            new CreateForumMetadataTables(),
            new CreatePollTables(),
            new CreateDiscussionStateTables(),
            new CreateContentModerationTables(),
            new CreateAttachmentPipelineTables(),
            new CreateSocialInteractionTables(),
            new CreateProfileActivityTables(),
            new CreateNotificationAlertTables(),
            new CreateNotificationSoundTables(),
            new CreateSearchIndexLifecycleTables(),
        ];
    }
}
