<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Migration;

use Forwext\Core\Install\CoreMigrationRegistry;
use Forwext\Database\Migrations\Core\CreateAbusePreventionTables;
use Forwext\Database\Migrations\Core\CreateAiModerationWorkflow;
use Forwext\Database\Migrations\Core\CreateAiModerationPrivacyCostPolicy;
use Forwext\Database\Migrations\Core\CreateAttachmentPipelineTables;
use Forwext\Database\Migrations\Core\CreateContentModerationTables;
use Forwext\Database\Migrations\Core\CreateContentManagerSystem;
use Forwext\Database\Migrations\Core\CreateCoreAuditStream;
use Forwext\Database\Migrations\Core\CreateIndependentModerationOversight;
use Forwext\Database\Migrations\Core\CreateSupportTicketDomain;
use Forwext\Database\Migrations\Core\CreateSpellcheckSystem;
use Forwext\Database\Migrations\Core\CreateSupportTicketIntake;
use Forwext\Database\Migrations\Core\CreateSupportConversationTools;
use Forwext\Database\Migrations\Core\CreateFaqSystem;
use Forwext\Database\Migrations\Core\CreateFaqSupportBridge;
use Forwext\Database\Migrations\Core\CreateGiveawayDomain;
use Forwext\Database\Migrations\Core\CreateGiveawayParticipation;
use Forwext\Database\Migrations\Core\CreateGiveawayDrawSystem;
use Forwext\Database\Migrations\Core\CreateGiveawayDrawPopulation;
use Forwext\Database\Migrations\Core\CreateEasterEggSystem;
use Forwext\Database\Migrations\Core\AllowNullableEasterEggPath;
use Forwext\Database\Migrations\Core\CreateTrophySystem;
use Forwext\Database\Migrations\Core\CreateRewardPromotionSystem;
use Forwext\Database\Migrations\Core\CreatePromotionSystem;
use Forwext\Database\Migrations\Core\AddPromotionRevocationPolicy;
use Forwext\Database\Migrations\Core\CreateMarketplaceDomain;
use Forwext\Database\Migrations\Core\CreateMarketplaceDiscoveryUx;
use Forwext\Database\Migrations\Core\CreateMarketplaceExternalSale;
use Forwext\Database\Migrations\Core\CreateMarketplaceNativePurchase;
use Forwext\Database\Migrations\Core\CreateMarketplaceDigitalDelivery;
use Forwext\Database\Migrations\Core\CreatePaymentAbstraction;
use Forwext\Database\Migrations\Core\ScopePaymentRefundReference;
use Forwext\Database\Migrations\Core\CreateSupportReportingAudit;
use Forwext\Database\Migrations\Core\CreateBugReportWorkflow;
use Forwext\Database\Migrations\Core\CreateBugDiagnosticContext;
use Forwext\Database\Migrations\Core\CreateBugReportFormIntake;
use Forwext\Database\Migrations\Core\CreateBugReportConversation;
use Forwext\Database\Migrations\Core\CreateBugStaffWorkflow;
use Forwext\Database\Migrations\Core\CreateDisciplineTables;
use Forwext\Database\Migrations\Core\CreateDiscussionStateTables;
use Forwext\Database\Migrations\Core\CreateForumMetadataTables;
use Forwext\Database\Migrations\Core\CreateForumNodeTables;
use Forwext\Database\Migrations\Core\CreateNotificationAlertTables;
use Forwext\Database\Migrations\Core\CreateNotificationSoundTables;
use Forwext\Database\Migrations\Core\CreatePermissionEngineTables;
use Forwext\Database\Migrations\Core\CreatePermissionTemplateTables;
use Forwext\Database\Migrations\Core\CreatePollTables;
use Forwext\Database\Migrations\Core\CreatePostDomainTables;
use Forwext\Database\Migrations\Core\CreateProfileActivityTables;
use Forwext\Database\Migrations\Core\CreatePortfolioSystem;
use Forwext\Database\Migrations\Core\CreateReferralSystem;
use Forwext\Database\Migrations\Core\UpgradePortfolioMediaStorage;
use Forwext\Database\Migrations\Core\CreateRoleAppearanceTable;
use Forwext\Database\Migrations\Core\CreateRoleGroupTables;
use Forwext\Database\Migrations\Core\CreateSocialInteractionTables;
use Forwext\Database\Migrations\Core\CreateThreadDomainTables;
use Forwext\Database\Migrations\Core\CreateThreadFreshnessSystem;
use Forwext\Database\Migrations\Core\RegisterFirstPartyPermissionNamespaces;
use Forwext\Database\Migrations\Core\IntegrateContentGovernancePermissions;
use PHPUnit\Framework\TestCase;

final class CoreMigrationRegistryTest extends TestCase
{
    public function testInstallerMigrationIdsAreValidUniqueAndChronological(): void
    {
        $ids = [];
        foreach (CoreMigrationRegistry::all() as $migration) $ids[] = $migration->id()->value();
        self::assertNotEmpty($ids);
        self::assertCount(count($ids), array_unique($ids));
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $ids, 'Core migrations must be registered in chronological id order.');
    }

    public function testInstallerIncludesCurrentRolePermissionAndForumMigrations(): void
    {
        $classes = array_map(static fn (object $migration): string => $migration::class, CoreMigrationRegistry::all());
        self::assertContains(CreateRoleGroupTables::class, $classes);
        self::assertContains(CreatePermissionEngineTables::class, $classes);
        self::assertContains(CreatePermissionTemplateTables::class, $classes);
        self::assertContains(CreateRoleAppearanceTable::class, $classes);
        self::assertContains(RegisterFirstPartyPermissionNamespaces::class, $classes);
        self::assertContains(CreateForumNodeTables::class, $classes);
        self::assertContains(CreateThreadDomainTables::class, $classes);
        self::assertContains(CreatePostDomainTables::class, $classes);
        self::assertContains(CreateForumMetadataTables::class, $classes);
        self::assertContains(CreatePollTables::class, $classes);
        self::assertContains(CreateDiscussionStateTables::class, $classes);
        self::assertContains(CreateContentModerationTables::class, $classes);
        self::assertContains(CreateDisciplineTables::class, $classes);
        self::assertContains(CreateAbusePreventionTables::class, $classes);
        self::assertContains(CreateCoreAuditStream::class, $classes);
        self::assertContains(CreateIndependentModerationOversight::class, $classes);
        self::assertContains(CreateSupportTicketDomain::class, $classes);
        self::assertContains(CreateSupportTicketIntake::class, $classes);
        self::assertContains(CreateSupportConversationTools::class, $classes);
        self::assertContains(CreateFaqSystem::class, $classes);
        self::assertContains(CreateFaqSupportBridge::class, $classes);
        self::assertContains(CreateSupportReportingAudit::class, $classes);
        self::assertContains(CreateBugReportWorkflow::class, $classes);
        self::assertContains(CreateBugDiagnosticContext::class, $classes);
        self::assertContains(CreateBugReportFormIntake::class, $classes);
        self::assertContains(CreateBugReportConversation::class, $classes);
        self::assertContains(CreateBugStaffWorkflow::class, $classes);
        self::assertContains(CreateAiModerationWorkflow::class, $classes);
        self::assertContains(CreateAiModerationPrivacyCostPolicy::class, $classes);
        self::assertContains(CreateSpellcheckSystem::class, $classes);
        self::assertContains(CreateContentManagerSystem::class, $classes);
        self::assertContains(CreateThreadFreshnessSystem::class, $classes);
        self::assertContains(IntegrateContentGovernancePermissions::class, $classes);
        self::assertContains(CreateAttachmentPipelineTables::class, $classes);
        self::assertContains(CreateSocialInteractionTables::class, $classes);
        self::assertContains(CreateProfileActivityTables::class, $classes);
        self::assertContains(CreateNotificationAlertTables::class, $classes);
        self::assertContains(CreateNotificationSoundTables::class, $classes);
        self::assertContains(CreatePortfolioSystem::class, $classes);
        self::assertContains(UpgradePortfolioMediaStorage::class, $classes);
        self::assertContains(CreateReferralSystem::class, $classes);
        self::assertContains(CreateGiveawayDomain::class, $classes);
        self::assertContains(CreateGiveawayParticipation::class, $classes);
        self::assertContains(CreateGiveawayDrawSystem::class, $classes);
        self::assertContains(CreateGiveawayDrawPopulation::class, $classes);
        self::assertContains(CreateEasterEggSystem::class, $classes);
        self::assertContains(AllowNullableEasterEggPath::class, $classes);
        self::assertContains(CreateTrophySystem::class, $classes);
        self::assertContains(CreateRewardPromotionSystem::class, $classes);
        self::assertContains(CreatePromotionSystem::class, $classes);
        self::assertContains(AddPromotionRevocationPolicy::class, $classes);
        self::assertContains(CreateMarketplaceDomain::class, $classes);
        self::assertContains(CreateMarketplaceDiscoveryUx::class, $classes);
        self::assertContains(CreateMarketplaceExternalSale::class, $classes);
        self::assertContains(CreateMarketplaceNativePurchase::class, $classes);
        self::assertContains(CreatePaymentAbstraction::class, $classes);
        self::assertContains(ScopePaymentRefundReference::class, $classes);
        self::assertContains(CreateMarketplaceDigitalDelivery::class, $classes);
    }
}
