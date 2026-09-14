<?php

declare(strict_types=1);

namespace Forwext\Core\Domain\User;

use RuntimeException;

final class UserConcurrencyException extends RuntimeException
{
}
