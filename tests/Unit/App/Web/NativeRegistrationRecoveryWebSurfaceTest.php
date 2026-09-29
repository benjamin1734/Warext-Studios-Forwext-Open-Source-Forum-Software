<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class NativeRegistrationRecoveryWebSurfaceTest extends TestCase
{
    public function testNativeRegistrationAndRecoveryRoutesAreCsrfProtected(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString("'auth.register'", $factory);
        self::assertStringContainsString("new PathTemplate('/register')", $factory);
        self::assertStringContainsString("'auth.verify-email'", $factory);
        self::assertStringContainsString("new PathTemplate('/verify-email')", $factory);
        self::assertStringContainsString("'auth.forgot-password'", $factory);
        self::assertStringContainsString("new PathTemplate('/forgot-password')", $factory);
        self::assertStringContainsString("'auth.reset-password'", $factory);
        self::assertStringContainsString("new PathTemplate('/reset-password')", $factory);
        self::assertStringContainsString('$authCsrf', $factory);
    }

    public function testRegistrationCompositionReusesProductionSecurityServices(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');

        self::assertStringContainsString('new RegistrationService(', $factory);
        self::assertStringContainsString('new DatabaseRegistrationRateLimiter($database)', $factory);
        self::assertStringContainsString('new RegistrationFingerprint(', $factory);
        self::assertStringContainsString('new CloudflareTurnstileVerifier(', $factory);
        self::assertStringContainsString('new NativeTurnstileTransport()', $factory);
        self::assertStringContainsString('new PasswordCredentialProvisioner($credentials, $passwordHasher)', $factory);
        self::assertStringContainsString('new EmailVerificationService($database, $users, $verificationTokens)', $factory);
        self::assertStringContainsString('new PasswordResetService(', $factory);
        self::assertStringContainsString('new DatabaseAuthChallengeTokenStore($database)', $factory);
    }

    public function testAuthLinkDeliveryFailsClosedWhenTransactionalMailIsUnavailable(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $registration = (string) file_get_contents($root . '/app/Web/Auth/RegisterHandler.php');
        $recovery = (string) file_get_contents($root . '/app/Web/Auth/PasswordResetRequestHandler.php');

        self::assertStringContainsString('new MailAuthLinkDelivery(', $factory);
        self::assertStringContainsString('$this->mailTransport($config, $secretStore)', $factory);
        self::assertStringContainsString('!$this->delivery->available()', $registration);
        self::assertStringContainsString('!$this->delivery->available()', $recovery);
        self::assertStringContainsString('sendEmailVerification(', $registration);
        self::assertStringContainsString('sendPasswordReset(', $recovery);
        self::assertStringNotContainsString('emailVerificationToken) .', $registration);
        self::assertStringNotContainsString("name=\"token\" value=\"' . self::e(\$token)", $recovery);
    }

    public function testPasswordRecoveryResponseDoesNotRevealAccountExistence(): void
    {
        $root = dirname(__DIR__, 4);
        $handler = (string) file_get_contents($root . '/app/Web/Auth/PasswordResetRequestHandler.php');

        self::assertStringContainsString('Bilgiler bir hesapla eşleşiyorsa', $handler);
        self::assertStringContainsString('catch (AuthException|InvalidArgumentException)', $handler);
        self::assertStringNotContainsString('Hesap bulunamadı', $handler);
        self::assertStringNotContainsString('E-posta bulunamadı', $handler);
    }

    public function testVerificationAndPasswordResetRequireExplicitPostCompletion(): void
    {
        $root = dirname(__DIR__, 4);
        $verification = (string) file_get_contents($root . '/app/Web/Auth/EmailVerificationHandler.php');
        $reset = (string) file_get_contents($root . '/app/Web/Auth/PasswordResetHandler.php');

        self::assertStringContainsString('if ($request->method() === HttpMethod::Post)', $verification);
        self::assertStringContainsString('$this->verification->verify($token)', $verification);
        self::assertStringContainsString('name="_csrf"', $verification);
        self::assertStringContainsString('if ($request->method() === HttpMethod::Post)', $reset);
        self::assertStringContainsString('$this->passwordReset->reset($token, $password)', $reset);
        self::assertStringContainsString('name="_csrf"', $reset);
    }

    public function testGuestNavigationAndLoginExposeAccountEntryLinks(): void
    {
        $root = dirname(__DIR__, 4);
        $profile = (string) file_get_contents($root . '/app/Web/Profile/ProfileHtml.php');
        $css = (string) file_get_contents($root . '/public/assets/site-base.css')
            . (string) file_get_contents($root . '/public/assets/site-shell.css');
        $login = (string) file_get_contents($root . '/app/Web/Auth/LoginHandler.php');

        self::assertStringContainsString('data-nav-key="auth.register"', $profile);
        self::assertStringContainsString('/forgot-password', $login);
        self::assertStringContainsString('/register', $login);
        self::assertStringContainsString('.auth-entry-check', $css);
        self::assertStringContainsString('.auth-entry-links', $css);
    }
}
