<?php

declare(strict_types=1);

namespace Forwext\Core\Mail;

use Forwext\Core\Domain\User\EmailAddress;
use InvalidArgumentException;

final readonly class MailMessage
{
    public function __construct(
        public EmailAddress $recipient,
        public string $subject,
        public string $textBody,
        public ?string $htmlBody = null,
    ) {
        if ($subject === '' || strlen($subject) > 255 || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1) {
            throw new InvalidArgumentException('Mail subject is invalid.');
        }
        if ($textBody === '' || strlen($textBody) > 262144 || str_contains($textBody, "\0")) {
            throw new InvalidArgumentException('Mail text body is invalid.');
        }
        if ($htmlBody !== null && ($htmlBody === '' || strlen($htmlBody) > 524288 || str_contains($htmlBody, "\0"))) {
            throw new InvalidArgumentException('Mail HTML body is invalid.');
        }
    }
}
