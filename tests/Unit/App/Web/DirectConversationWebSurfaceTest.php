<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class DirectConversationWebSurfaceTest extends TestCase
{
    public function testNativePrivateConversationRoutesAreAuthenticatedCsrfProtectedAndPrivate(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Conversation/DirectConversationHandler.php');

        self::assertStringContainsString("new PathTemplate('/account/conversations')", $factory);
        self::assertStringContainsString("new PathTemplate('/account/conversations/{conversationId}'", $factory);
        self::assertStringContainsString('new DirectConversationHandler(', $factory);
        self::assertStringContainsString('$conversationCsrf', $factory);
        self::assertStringContainsString("'conversation'", $factory);
        self::assertStringContainsString('forwext.csrf.conversation.v1', $factory);

        self::assertStringContainsString('Authentication required.', $handler);
        self::assertStringContainsString('CsrfMiddleware::ATTRIBUTE_TOKEN', $handler);
        self::assertStringContainsString('otherParticipant($actor, $conversationId)', $handler);
        self::assertStringContainsString("\$action === 'star' || \$action === 'unstar'", $handler);
        self::assertStringContainsString("\$action === 'leave'", $handler);
        self::assertStringContainsString("['all', 'starred']", $handler);
        self::assertStringContainsString("Response::text('Not Found', 404)", $handler);
        self::assertStringContainsString('private, no-store', $handler);
        self::assertStringContainsString('X-Robots-Tag', $handler);
        self::assertStringContainsString('noindex,nofollow', $handler);
    }

    public function testConversationDomainRequiresPermissionAndHonorsIgnoreRelationships(): void
    {
        $root = dirname(__DIR__, 4);
        $service = (string) file_get_contents($root . '/core/Conversation/DirectConversationService.php');
        $repository = (string) file_get_contents(
            $root . '/core/Conversation/DatabaseDirectConversationRepository.php',
        );

        self::assertStringContainsString("public const USE_PERMISSION = 'conversation.use'", $service);
        self::assertStringContainsString('PermissionKey::fromString(self::USE_PERMISSION)', $service);
        self::assertStringContainsString('$this->social->isIgnoring($actor, $target)', $service);
        self::assertStringContainsString('$this->social->isIgnoring($target, $actor)', $service);
        self::assertStringContainsString('Kendine özel mesaj gönderemezsin.', $service);
        self::assertStringContainsString('pair_key', $repository);
        self::assertStringContainsString('FOR UPDATE', $repository);
        self::assertStringContainsString('WHERE p.user_id=:actor_id', $repository);
        self::assertStringContainsString('last_read_at_utc', $repository);
        self::assertStringContainsString('starred_at_utc', $repository);
        self::assertStringContainsString('left_at_utc', $repository);
        self::assertStringContainsString('activeParticipantExists', $repository);
        self::assertStringContainsString('um.author_user_id IS NULL', $repository);
    }

    public function testPrivateConversationUiUsesSharedResponsiveAccountSurfaces(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Conversation/DirectConversationHtml.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $dashboard = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHtml.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('conversation-page discovery-page', $html);
        self::assertStringContainsString('surface-panel conversation-list-panel', $html);
        self::assertStringContainsString('class="conversation-message', $html);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('maxlength="10000"', $html);
        self::assertStringContainsString('nl2br(self::e($message->body)', $html);
        self::assertStringContainsString('conversation-filters', $html);
        self::assertStringContainsString('conversation-star-button', $html);
        self::assertStringContainsString('name="action" value="leave"', $html);
        self::assertStringNotContainsString('name="action" value="invite"', $html);
        self::assertStringNotContainsString('name="action" value="lock"', $html);
        self::assertStringContainsString("otherUsername !== 'Silinmiş kullanıcı'", $html);

        self::assertStringContainsString("'conversations.own'", $navigation);
        self::assertStringContainsString("'/account/conversations'", $navigation);
        self::assertStringContainsString('nav-icon-link--messages', $profile);
        self::assertStringContainsString("['Özel Mesajlar'", $dashboard);

        self::assertStringContainsString('/* direct-conversations-v1 */', $css);
        self::assertStringContainsString('.conversation-layout', $css);
        self::assertStringContainsString('.conversation-row.is-unread', $css);
        self::assertStringContainsString('.conversation-message.is-own', $css);
        self::assertStringContainsString('@media(max-width:760px)', $css);
        self::assertStringContainsString('/* direct-conversations-v2 */', $css);
        self::assertStringContainsString('.conversation-row-shell', $css);
    }

    public function testMigrationIsPartOfTheProductionInstallerRegistry(): void
    {
        $root = dirname(__DIR__, 4);
        $registry = (string) file_get_contents($root . '/core/Install/CoreMigrationRegistry.php');
        $migration = (string) file_get_contents(
            $root . '/database/migrations/core/CreateDirectConversationSystem.php',
        );

        self::assertStringContainsString('new CreateDirectConversationSystem()', $registry);
        self::assertStringContainsString('new AddDirectConversationParticipantManagement()', $registry);
        self::assertStringContainsString('20261003150000_direct_conversation_system', $migration);
        self::assertStringContainsString("'conversation.use'", $migration);
        $managementMigration = (string) file_get_contents(
            $root . '/database/migrations/core/AddDirectConversationParticipantManagement.php',
        );
        self::assertStringContainsString('starred_at_utc', $managementMigration);
        self::assertStringContainsString('left_at_utc', $managementMigration);
        self::assertStringContainsString('uq_forwext_direct_pair', $migration);
    }
}
