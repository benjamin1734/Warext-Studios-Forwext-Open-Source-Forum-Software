<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Install;

use Forwext\Core\Install\InstallationInput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InstallationInputTest extends TestCase
{
    public function testLegacyDatabaseOnlyInputRemainsSupported(): void
    {
        $input = new InstallationInput(
            'https://forum.example.com/community',
            'localhost',
            3306,
            'forwext',
            'forwext',
            'secret',
        );

        self::assertSame('https://forum.example.com/community', $input->normalizedCanonicalUrl());
        self::assertFalse($input->hasAdministratorBootstrap());
        self::assertSame('forwext-balanced', $input->themeKey());
        self::assertNull($input->enabledModules);
    }

    public function testCompleteInstallerInputNormalizesModuleSelection(): void
    {
        $input = new InstallationInput(
            'https://forum.example.com',
            'localhost',
            3306,
            'forwext',
            'forwext',
            'secret',
            siteName: 'Example Forum',
            siteLocale: 'tr-TR',
            siteTimezone: 'Europe/Istanbul',
            adminUsername: 'Admin',
            adminEmail: 'admin@example.com',
            adminPassword: 'Very-Strong-Password-123',
            mailDriver: 'smtp',
            mailFromAddress: 'forum@example.com',
            smtpHost: 'smtp.example.com',
            enabledModules: ['support', 'faq', 'support'],
            themePreset: 'compact',
        );

        self::assertTrue($input->hasAdministratorBootstrap());
        self::assertSame(['faq', 'support'], $input->enabledModules);
        self::assertSame('forwext-compact', $input->themeKey());
    }

    public function testPartialAdministratorCredentialsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InstallationInput(
            'https://forum.example.com',
            'localhost',
            3306,
            'forwext',
            'forwext',
            'secret',
            adminUsername: 'Admin',
        );
    }

    public function testSmtpRequiresHostAndSenderAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new InstallationInput(
            'https://forum.example.com',
            'localhost',
            3306,
            'forwext',
            'forwext',
            'secret',
            mailDriver: 'smtp',
        );
    }
}
