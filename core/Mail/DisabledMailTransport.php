<?php

declare(strict_types=1);

namespace Forwext\Core\Mail;

final readonly class DisabledMailTransport implements MailTransport
{
    public function available(): bool
    {
        return false;
    }

    public function send(MailMessage $message): void
    {
        throw new MailException('Mail transport is unavailable.');
    }
}
