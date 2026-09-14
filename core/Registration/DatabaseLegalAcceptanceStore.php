<?php

declare(strict_types=1);

namespace Forwext\Core\Registration;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;

final readonly class DatabaseLegalAcceptanceStore implements LegalAcceptanceStore
{
    public function __construct(private TransactionalQueryExecutor $database)
    {
    }

    public function record(
        EntityId $userId,
        LegalDocumentRequirement $document,
        DateTimeImmutable $acceptedAt,
        string $clientFingerprint,
    ): void {
        UserId::assert($userId);
        if (preg_match('/^[a-f0-9]{64}$/D', $clientFingerprint) !== 1) {
            throw new RegistrationException('Legal acceptance client fingerprint is invalid.');
        }
        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO `forwext_user_legal_acceptances` '
            . '(`user_id`, `document_type`, `document_version`, `content_sha256`, `accepted_at_utc`, `client_fingerprint`) '
            . 'VALUES (:user_id, :document_type, :document_version, :content_sha256, :accepted_at_utc, :client_fingerprint)',
            [
                'user_id' => $userId->value(),
                'document_type' => $document->type,
                'document_version' => $document->version,
                'content_sha256' => $document->contentSha256,
                'accepted_at_utc' => $acceptedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'client_fingerprint' => $clientFingerprint,
            ],
        ));
        if ($affected !== 1) {
            throw new RegistrationException('Legal acceptance was not persisted.');
        }
    }
}
