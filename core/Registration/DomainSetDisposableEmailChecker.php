<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use Forwext\Core\Domain\User\EmailAddress;
use InvalidArgumentException;

final readonly class DomainSetDisposableEmailChecker implements DisposableEmailChecker
{
    /** @var array<string, true> */
    private array $domains;

    /** @param list<string> $domains */
    public function __construct(array $domains = [])
    {
        $normalized = [];
        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain === '' || filter_var('probe@' . $domain, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Disposable email domain is invalid.');
            }
            $normalized[$domain] = true;
        }
        $this->domains = $normalized;
    }

    public function isDisposable(EmailAddress $email): bool
    {
        $position = strrpos($email->value(), '@');
        if ($position === false) {
            return true;
        }
        return isset($this->domains[substr($email->value(), $position + 1)]);
    }
}
