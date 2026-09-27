<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Delivery;

use Forwext\Core\Domain\User\EmailAddress;

interface AuthLinkDelivery
{
    public function available(): bool;

    public function sendEmailVerification(EmailAddress $recipient, string $token): bool;

    public function sendPasswordReset(EmailAddress $recipient, string $token): bool;
}
