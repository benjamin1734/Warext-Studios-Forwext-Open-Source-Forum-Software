<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Mail;

use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Mail\MailMessage;
use Forwext\Core\Mail\MailMessageFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MailMessageFormatterTest extends TestCase
{
    public function testSmtpFormattingIsUtf8SafeAndUsesCrlfOnly(): void
    {
        $message = new MailMessage(
            EmailAddress::fromString('user@example.com'),
            'Forwext — E-posta doğrulama',
            "Merhaba\nBağlantıyı kullan.",
            '<p>Merhaba</p>',
        );

        $raw = MailMessageFormatter::forSmtp(
            $message,
            EmailAddress::fromString('no-reply@example.com'),
            'Forwext Türkiye',
        );

        self::assertStringContainsString('To: <user@example.com>', $raw);
        self::assertStringContainsString('Subject: =?UTF-8?B?', $raw);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $raw);
        self::assertStringContainsString('Auto-Submitted: auto-generated', $raw);
        self::assertDoesNotMatchRegularExpression('/(?<!\r)\n/', $raw);
    }

    public function testHeaderInjectionIsRejectedBeforeFormatting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailMessage(
            EmailAddress::fromString('user@example.com'),
            "Normal\r\nBcc: attacker@example.com",
            'Body',
        );
    }
}
