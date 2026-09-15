<?php

declare(strict_types=1);

namespace Forwext\Core\Auth\Mfa\Passkey;

enum PasskeyCeremonyPurpose: string
{
    case Registration = 'registration';
    case Authentication = 'authentication';
}
