<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class OAuthConnectedAccountWebSurfaceTest extends TestCase
{
    public function testFactoryWiresNativeOAuthLoginLinkingAndAccountSecurity(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        foreach ([
            "new PathTemplate('/oauth/{provider}/start'",
            "new PathTemplate('/oauth/{provider}/callback'",
            "new PathTemplate('/account/security')",
            'new OAuthConnectedAccountService(',
            'new DatabaseConnectedAccountStore($database)',
            'new DatabaseOAuthTransactionStore($database)',
            'new NativeOAuthHttpClient(',
            'OAuthProviderRegistryFactory::fromConfig($oauthConfig)',
            '$oauthStartHandler',
            '$oauthCallbackHandler',
            '$accountSecurityHandler',
        ] as $contract) {
            self::assertStringContainsString($contract, $factory);
        }

        self::assertStringContainsString('$authCsrf', $factory);
        self::assertStringContainsString('$authenticationAvailability', $factory);
        self::assertStringContainsString('$authFingerprints', $factory);
        self::assertStringContainsString('$authDevices', $factory);
        self::assertStringContainsString('$mfaLoginGate', $factory);
        self::assertStringContainsString('$mfaCompletion', $factory);
    }

    public function testOAuthCallbackDoesNotBypassDisciplineDeviceCredentialOrMfaChecks(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Auth/OAuthCallbackHandler.php');

        self::assertStringContainsString('$this->availability->allows($user->id())', $handler);
        self::assertStringContainsString('$this->credentials->find($user->id())', $handler);
        self::assertStringContainsString('$this->devices->touch(', $handler);
        self::assertStringContainsString('$this->mfaGate->enforce(', $handler);
        self::assertStringContainsString('catch (SecondFactorRequiredException $exception)', $handler);
        self::assertStringContainsString('$this->mfaCompletion->completeCode(', $handler);
        self::assertStringContainsString('$this->mfaCompletion->completePasskey(', $handler);
        self::assertStringContainsString('$this->sessions->establish(', $handler);
        self::assertStringContainsString('LoginOutcome::MfaRequired', $handler);
        self::assertStringContainsString('LoginOutcome::Success', $handler);
        self::assertStringContainsString('secure: true', $handler);
        self::assertStringContainsString('httpOnly: true', $handler);
        self::assertStringContainsString('sameSite: SameSite::Lax', $handler);
        self::assertStringContainsString('Cache-Control', $handler);
    }

    public function testOAuthStartSeparatesGuestLoginFromAuthenticatedLinking(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Auth/OAuthStartHandler.php');

        self::assertStringContainsString('$request->method() === HttpMethod::Post', $handler);
        self::assertStringContainsString('($body[\'intent\'] ?? null) !== \'link\'', $handler);
        self::assertStringContainsString('$this->oauth->begin($provider, $redirectUri, $actor)', $handler);
        self::assertStringContainsString('$this->oauth->begin($provider, $redirectUri)', $handler);
        self::assertStringContainsString("Referrer-Policy', 'no-referrer", $handler);
        self::assertStringContainsString('OAuth provider unavailable.', $handler);
    }

    public function testAccountSecurityListsAndUnlinksOnlyOwnedConnectedAccounts(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Auth/AccountSecurityHandler.php');
        $html = (string) file_get_contents($root . '/app/Web/Auth/AccountSecurityHtml.php');
        $store = (string) file_get_contents($root . '/core/Auth/OAuth/DatabaseConnectedAccountStore.php');

        self::assertStringContainsString('$this->accounts->forUser($actor)', $handler);
        self::assertStringContainsString('$this->oauth->unlink($actor, $provider)', $handler);
        self::assertStringContainsString('in_array($provider, [\'google\',\'discord\'], true)', $handler);
        self::assertStringContainsString('name="_csrf"', $html);
        self::assertStringContainsString('name="intent" value="link"', $html);
        self::assertStringContainsString('name="action" value="unlink"', $html);
        self::assertStringContainsString('/oauth/', $html);
        self::assertStringContainsString('WHERE `user_id`=:user_id ORDER BY', $store);
    }

    public function testLoginAndAccountCenterExposeOnlyConfiguredOAuthEntryPoints(): void
    {
        $root = dirname(__DIR__, 4);
        $login = (string) file_get_contents($root . '/app/Web/Auth/LoginHandler.php');
        $dashboard = (string) file_get_contents($root . '/app/Web/Account/AccountDashboardHtml.php');
        $navigation = (string) file_get_contents($root . '/core/Ui/Navigation/NavigationRegistry.php');
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-pages.css');

        self::assertStringContainsString('$this->oauthProviders', $login);
        self::assertStringContainsString('/oauth/', $login);
        self::assertStringContainsString('Hesap Güvenliği', $dashboard);
        self::assertStringContainsString('/account/security', $dashboard);
        self::assertStringContainsString("'security.own'", $navigation);
        self::assertStringContainsString("'security.own' => ['Güvenlik'", $profile);
        self::assertStringContainsString('/* oauth-account-security-v1 */', $css);
        self::assertStringContainsString('.account-security-provider', $css);
    }
}
