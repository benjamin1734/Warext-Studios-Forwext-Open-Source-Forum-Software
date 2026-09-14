<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use Forwext\Core\Domain\User\EmailAddress;

interface DisposableEmailChecker
{
    public function isDisposable(EmailAddress $email): bool;
}
