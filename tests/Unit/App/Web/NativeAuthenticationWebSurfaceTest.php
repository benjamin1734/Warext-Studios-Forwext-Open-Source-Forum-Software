<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class NativeAuthenticationWebSurfaceTest extends TestCase
{
    public function testNativeLoginAndLogoutRoutesAreCsrfProtected(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("'auth.login'", $factory);
        self::assertStringContainsString("new PathTemplate('/login')", $factory);
        self::assertStringContainsString("'auth.logout'", $factory);
        self::assertStringContainsString("new PathTemplate('/logout')", $factory);
        self::assertStringContainsString('$authCsrf', $factory);
        self::assertStringContainsString("'auth-entry'", $factory);
        self::assertStringContainsString('new LoginHandler(', $factory);
        self::assertStringContainsString('new LogoutHandler(', $factory);
    }

    public function testLoginCompositionUsesExistingAuthenticationSecurityServices(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString('new AuthenticationService(', $factory);
        self::assertStringContainsString('new DatabaseAuthenticationRateLimiter($database)', $factory);
        self::assertStringContainsString('new DatabaseLoginHistoryRecorder($database)', $factory);
        self::assertStringContainsString('new DatabaseDeviceRepository($database)', $factory);
        self::assertStringContainsString('new DatabaseMfaLoginGate(', $factory);
        self::assertStringContainsString('new MfaLoginCompletionService(', $factory);
        self::assertStringContainsString('new DatabaseTotpService(', $factory);
        self::assertStringContainsString('new RecoveryCodeService(', $factory);
        self::assertStringContainsString('new DatabasePasskeyService(', $factory);
        self::assertStringContainsString('new WebAuthnLibEngine(', $factory);
        self::assertStringContainsString('new DatabaseDisciplineAuthenticationAvailability($database)', $factory);
        self::assertStringContainsString('new TrustedProxyResolver(', $factory);
    }

    public function testLoginHandlerFailsClosedWhenMfaIsRequiredAndIssuesSecureSessionCookieOnlyAfterSuccess(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Auth/LoginHandler.php');

        self::assertStringContainsString('catch (SecondFactorRequiredException $exception)', $handler);
        self::assertStringContainsString('$this->mfaCompletion->completeCode(', $handler);
        self::assertStringContainsString('MfaMethod::Totp', $handler);
        self::assertStringContainsString('MfaMethod::RecoveryCode', $handler);
        self::assertStringContainsString('mfa_challenge', $handler);
        self::assertStringContainsString('MFA kurulumu gerekli.', $handler);
        self::assertStringContainsString('Passkey tarayıcı doğrulama akışı henüz native giriş ekranına bağlanmadı.', $handler);
        self::assertStringContainsString('withCookie(new ResponseCookie(', $handler);
        self::assertStringContainsString('secure: true', $handler);
        self::assertStringContainsString('httpOnly: true', $handler);
        self::assertStringContainsString('sameSite: SameSite::Lax', $handler);
        self::assertStringContainsString('name="_csrf"', $handler);
    }

    public function testLogoutRevokesServerSessionAndExpiresCookie(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Auth/LogoutHandler.php');

        self::assertStringContainsString('$this->sessions->revoke($sessionId)', $handler);
        self::assertStringContainsString('maxAge: 0', $handler);
        self::assertStringContainsString('secure: true', $handler);
        self::assertStringContainsString('httpOnly: true', $handler);
        self::assertStringContainsString('name="_csrf"', $handler);
    }

    public function testMfaGroupProviderUsesPrimaryAndSecondaryGroupsWithoutTreatingRolesAsGroups(): void
    {
        $root = dirname(__DIR__, 4);
        $provider = (string) file_get_contents($root . '/core/Auth/Mfa/Policy/DatabaseMfaUserGroupProvider.php');

        self::assertStringContainsString('primaryGroupId()', $provider);
        self::assertStringContainsString('secondaryGroupIds()', $provider);
        self::assertStringNotContainsString('roleIds()', $provider);
    }

    public function testHeaderExposesAuthenticationEntryActions(): void
    {
        $root = dirname(__DIR__, 4);
        $html = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');

        self::assertStringContainsString('data-nav-key="auth.login"', $html);
        self::assertStringContainsString('data-nav-key="auth.logout"', $html);
        self::assertStringContainsString('.auth-entry-form', $html);
    }
}
