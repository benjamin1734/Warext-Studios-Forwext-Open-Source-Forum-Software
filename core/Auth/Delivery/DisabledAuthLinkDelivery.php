<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Delivery;

use Forwext\Core\Domain\User\EmailAddress;

final readonly class DisabledAuthLinkDelivery implements AuthLinkDelivery
{
    public function available(): bool
    {
        return false;
    }

    public function sendEmailVerification(EmailAddress $recipient, string $token): bool
    {
        return false;
    }

    public function sendPasswordReset(EmailAddress $recipient, string $token): bool
    {
        return false;
    }
}
