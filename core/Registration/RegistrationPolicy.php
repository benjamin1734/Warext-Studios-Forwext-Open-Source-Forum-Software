<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use InvalidArgumentException;

final readonly class RegistrationPolicy
{
    /** @var array<string, LegalDocumentRequirement> */
    private array $legalDocuments;

    /** @param list<LegalDocumentRequirement> $legalDocuments */
    public function __construct(
        public RegistrationMode $mode = RegistrationMode::Open,
        public bool $emailVerificationRequired = true,
        public bool $captchaRequired = true,
        array $legalDocuments = [],
        public int $ipAttemptLimit = 10,
        public int $emailAttemptLimit = 5,
        public int $rateLimitWindowSeconds = 3600,
        public int $emailVerificationTtlSeconds = 86400,
    ) {
        if ($ipAttemptLimit < 1 || $emailAttemptLimit < 1) {
            throw new InvalidArgumentException('Registration rate limits must be positive.');
        }
        if ($rateLimitWindowSeconds < 60 || $rateLimitWindowSeconds > 86400) {
            throw new InvalidArgumentException('Registration rate-limit window must be between 60 and 86400 seconds.');
        }
        if ($emailVerificationTtlSeconds < 300 || $emailVerificationTtlSeconds > 604800) {
            throw new InvalidArgumentException('Email verification TTL must be between 300 and 604800 seconds.');
        }

        $normalized = [];
        foreach ($legalDocuments as $document) {
            if (!$document instanceof LegalDocumentRequirement) {
                throw new InvalidArgumentException('Registration legal requirements must be typed documents.');
            }
            if (isset($normalized[$document->type])) {
                throw new InvalidArgumentException('Registration legal document types must be unique.');
            }
            $normalized[$document->type] = $document;
        }
        ksort($normalized);
        $this->legalDocuments = $normalized;
    }

    /** @return array<string, LegalDocumentRequirement> */
    public function legalDocuments(): array
    {
        return $this->legalDocuments;
    }
}
