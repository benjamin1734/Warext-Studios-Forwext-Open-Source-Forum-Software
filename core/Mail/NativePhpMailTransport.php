<?php

declare(strict_types=1);

namespace Forwext\Core\Mail;

use Forwext\Core\Domain\User\EmailAddress;

final readonly class NativePhpMailTransport implements MailTransport
{
    public function __construct(
        private EmailAddress $fromAddress,
        private string $fromName,
    ) {
    }

    public function available(): bool
    {
        return function_exists('mail');
    }

    public function send(MailMessage $message): void
    {
        if (!$this->available()) {
            throw new MailException('Native PHP mail transport is unavailable.');
        }

        $parts = MailMessageFormatter::forPhpMail($message, $this->fromAddress, $this->fromName);
        $sent = @mail(
            $message->recipient->value(),
            $parts['subject'],
            $parts['body'],
            $parts['headers'],
        );
        if ($sent !== true) {
            throw new MailException('Native PHP mail delivery failed.');
        }
    }
}
