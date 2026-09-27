<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Mail;

use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Mail\NativeSmtpTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NativeSmtpTransportTest extends TestCase
{
    public function testValidTlsTransportCanBeComposedWithoutOpeningNetworkConnection(): void
    {
        $transport = new NativeSmtpTransport(
            'smtp.example.com',
            587,
            'starttls',
            'mailer@example.com',
            'secret-value',
            EmailAddress::fromString('no-reply@example.com'),
            'Forwext',
            10,
        );

        self::assertSame(function_exists('stream_socket_client') && extension_loaded('openssl'), $transport->available());
    }

    public function testAuthenticatedSmtpCannotBeConfiguredWithoutEncryption(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NativeSmtpTransport(
            'smtp.example.com',
            25,
            'none',
            'mailer@example.com',
            'secret-value',
            EmailAddress::fromString('no-reply@example.com'),
            'Forwext',
        );
    }

    public function testUnsafeSmtpHostShapeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NativeSmtpTransport(
            "smtp.example.com\r\nMAIL FROM:<evil@example.com>",
            587,
            'starttls',
            null,
            null,
            EmailAddress::fromString('no-reply@example.com'),
            'Forwext',
        );
    }
}
