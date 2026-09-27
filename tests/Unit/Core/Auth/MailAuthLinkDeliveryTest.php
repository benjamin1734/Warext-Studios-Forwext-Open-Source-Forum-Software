<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use Forwext\Core\Auth\Delivery\MailAuthLinkDelivery;
use Forwext\Core\Domain\User\EmailAddress;
use Forwext\Core\Mail\MailException;
use Forwext\Core\Mail\MailMessage;
use Forwext\Core\Mail\MailTransport;
use PHPUnit\Framework\TestCase;

final class MailAuthLinkDeliveryTest extends TestCase
{
    public function testVerificationAndResetLinksRespectCanonicalSubpath(): void
    {
        $transport = new CapturingMailTransport();
        $delivery = new MailAuthLinkDelivery($transport, 'https://forum.example.com/community', 'Forwext');
        $recipient = EmailAddress::fromString('user@example.com');
        $token = str_repeat('A', 43);

        self::assertTrue($delivery->sendEmailVerification($recipient, $token));
        self::assertStringContainsString(
            'https://forum.example.com/community/verify-email?token=' . $token,
            $transport->messages[0]->textBody,
        );

        self::assertTrue($delivery->sendPasswordReset($recipient, $token));
        self::assertStringContainsString(
            'https://forum.example.com/community/reset-password?token=' . $token,
            $transport->messages[1]->textBody,
        );
        self::assertStringNotContainsString($token, $transport->messages[1]->subject);
    }

    public function testInvalidTokenAndTransportFailureFailClosed(): void
    {
        $capture = new CapturingMailTransport();
        $delivery = new MailAuthLinkDelivery($capture, 'https://forum.example.com', 'Forwext');

        self::assertFalse($delivery->sendPasswordReset(
            EmailAddress::fromString('user@example.com'),
            'invalid token',
        ));
        self::assertSame([], $capture->messages);

        $failing = new MailAuthLinkDelivery(new FailingMailTransport(), 'https://forum.example.com', 'Forwext');
        self::assertFalse($failing->sendEmailVerification(
            EmailAddress::fromString('user@example.com'),
            str_repeat('B', 43),
        ));
    }
}

final class CapturingMailTransport implements MailTransport
{
    /** @var list<MailMessage> */
    public array $messages = [];

    public function available(): bool
    {
        return true;
    }

    public function send(MailMessage $message): void
    {
        $this->messages[] = $message;
    }
}

final class FailingMailTransport implements MailTransport
{
    public function available(): bool
    {
        return true;
    }

    public function send(MailMessage $message): void
    {
        throw new MailException('Synthetic failure.');
    }
}
