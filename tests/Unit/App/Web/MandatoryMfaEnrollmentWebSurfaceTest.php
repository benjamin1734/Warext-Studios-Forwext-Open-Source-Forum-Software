<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\App\Web;

use PHPUnit\Framework\TestCase;

final class MandatoryMfaEnrollmentWebSurfaceTest extends TestCase
{
    public function testMandatoryEnrollmentIsRoutedFromPasswordAndOAuthLogin(): void
    {
        $root = dirname(__DIR__, 4);
        $login = (string) file_get_contents($root . '/app/Web/Auth/LoginHandler.php');
        $oauth = (string) file_get_contents($root . '/app/Web/Auth/OAuthCallbackHandler.php');

        foreach ([$login, $oauth] as $source) {
            self::assertStringContainsString('$exception->enrollmentRequired', $source);
            self::assertStringContainsString('/mfa/enroll?challenge=', $source);
            self::assertStringContainsString('rawurlencode($exception->challengeToken)', $source);
        }
    }

    public function testEnrollmentRouteSupportsTotpPasskeyRecoveryCodesAndSecureSession(): void
    {
        $root = dirname(__DIR__, 4);
        $factory = (string) file_get_contents($root . '/app/Web/WebApplicationFactory.php');
        $handler = (string) file_get_contents($root . '/app/Web/Auth/MfaEnrollmentHandler.php');
        $asset = (string) file_get_contents($root . '/public/assets/auth-mfa.js');

        self::assertStringContainsString("new PathTemplate('/mfa/enroll')", $factory);
        self::assertStringContainsString('new PendingMfaEnrollmentService(', $factory);
        self::assertStringContainsString('availability: $mfaFactorAvailability', $factory);
        self::assertStringContainsString('$this->enrollment->confirmTotp(', $handler);
        self::assertStringContainsString('$this->enrollment->completePasskey(', $handler);
        self::assertStringContainsString('$this->completion->completeEnrollment(', $handler);
        self::assertStringContainsString('mfa-recovery-codes', $handler);
        self::assertStringContainsString('secure: true', $handler);
        self::assertStringContainsString('httpOnly: true', $handler);
        self::assertStringContainsString('sameSite: SameSite::Lax', $handler);
        self::assertStringContainsString('navigator.credentials.create', $asset);
        self::assertStringContainsString('parseCreationOptionsFromJSON', $asset);
        self::assertStringContainsString('data-auth-passkey-register-response', $asset);
    }

    public function testEnrollmentCompletionCannotCreateSessionBeforeFactorIsVerified(): void
    {
        $root = dirname(__DIR__, 4);
        $completion = (string) file_get_contents(
            $root . '/core/Auth/Mfa/Login/MfaLoginCompletionService.php',
        );

        self::assertStringContainsString('public function completeEnrollment(', $completion);
        self::assertStringContainsString('$this->availability->methods($grant->userId) === []', $completion);
        self::assertStringContainsString('MFA enrollment must be verified before login can be completed.', $completion);
        self::assertStringContainsString('return $this->finalize($challengeToken, $grant, false);', $completion);
    }
}
