<?php

declare(strict_types=1);

namespace Forwext\Core\Mail;

interface MailTransport
{
    public function available(): bool;

    public function send(MailMessage $message): void;
}
