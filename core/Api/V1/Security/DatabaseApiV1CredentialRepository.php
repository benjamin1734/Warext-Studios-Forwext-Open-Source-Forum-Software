<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1\Security;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\DatabaseConnection;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Domain\User\UserId;
use JsonException;
use RuntimeException;

final readonly class DatabaseApiV1CredentialRepository implements ApiV1CredentialRepository
{
    public function __construct(private DatabaseConnection $database)
    {
    }

    public function findBySecretHash(string $secretHash): ?ApiV1CredentialRecord
    {
        self::assertHash($secretHash);

        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT credential_id,owner_user_id,credential_type,display_name,secret_hash,scopes_json,'
            . 'expires_at_utc,revoked_at_utc,last_used_at_utc,created_at_utc '
            . 'FROM forwext_api_v1_credentials WHERE secret_hash=:secret_hash LIMIT 1',
            ['secret_hash'=>$secretHash],
        ));

        return $row === null ? null : self::hydrate($row);
    }

    public function save(ApiV1CredentialRecord $record): void
    {
        try {
            $scopes = json_encode(
                array_map(static fn (ApiV1Scope $scope): string => $scope->value, $record->scopes),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('API credential scopes cannot be encoded.', previous:$exception);
        }

        $affected = $this->database->execute(new CompiledQuery(
            'INSERT INTO forwext_api_v1_credentials '
            . '(credential_id,owner_user_id,credential_type,display_name,secret_hash,scopes_json,'
            . 'expires_at_utc,revoked_at_utc,last_used_at_utc,created_at_utc) '
            . 'VALUES (:credential_id,:owner_user_id,:credential_type,:display_name,:secret_hash,:scopes_json,'
            . ':expires_at,NULL,NULL,:created_at)',
            [
                'credential_id'=>$record->credentialId->value(),
                'owner_user_id'=>$record->ownerUserId->value(),
                'credential_type'=>$record->type->value,
                'display_name'=>$record->displayName,
                'secret_hash'=>$record->secretHash,
                'scopes_json'=>$scopes,
                'expires_at'=>self::formatNullable($record->expiresAt),
                'created_at'=>self::format($record->createdAt),
            ],
        ));
        if ($affected !== 1) {
            throw new RuntimeException('API credential was not persisted.');
        }
    }

    public function markUsed(EntityId $credentialId, DateTimeImmutable $at): void
    {
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_api_v1_credentials SET last_used_at_utc=:used_at '
            . 'WHERE credential_id=:credential_id AND revoked_at_utc IS NULL',
            ['used_at'=>self::format($at),'credential_id'=>$credentialId->value()],
        ));
    }

    public function revoke(EntityId $credentialId, EntityId $ownerUserId, DateTimeImmutable $at): void
    {
        UserId::assert($ownerUserId);
        $this->database->execute(new CompiledQuery(
            'UPDATE forwext_api_v1_credentials SET revoked_at_utc=:revoked_at '
            . 'WHERE credential_id=:credential_id AND owner_user_id=:owner_user_id AND revoked_at_utc IS NULL',
            [
                'revoked_at'=>self::format($at),
                'credential_id'=>$credentialId->value(),
                'owner_user_id'=>$ownerUserId->value(),
            ],
        ));
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): ApiV1CredentialRecord
    {
        try {
            $decoded = json_decode((string) ($row['scopes_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored API credential scopes are invalid.', previous:$exception);
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('Stored API credential scope list is invalid.');
        }

        $scopes = [];
        foreach ($decoded as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Stored API credential scope is invalid.');
            }
            $scopes[] = ApiV1Scope::tryFrom($value)
                ?? throw new RuntimeException('Stored API credential scope is unknown.');
        }

        return new ApiV1CredentialRecord(
            EntityId::fromString((string) ($row['credential_id'] ?? '')),
            UserId::fromStored((string) ($row['owner_user_id'] ?? '')),
            ApiV1PrincipalType::from((string) ($row['credential_type'] ?? '')),
            (string) ($row['display_name'] ?? ''),
            (string) ($row['secret_hash'] ?? ''),
            $scopes,
            self::parse((string) ($row['created_at_utc'] ?? '')),
            self::parseNullable($row['expires_at_utc'] ?? null),
            self::parseNullable($row['revoked_at_utc'] ?? null),
            self::parseNullable($row['last_used_at_utc'] ?? null),
        );
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new RuntimeException('API credential lookup hash is invalid.');
        }
    }

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function formatNullable(?DateTimeImmutable $time): ?string
    {
        return $time === null ? null : self::format($time);
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw new RuntimeException('Stored API credential timestamp is invalid.');
        }

        return $time;
    }

    private static function parseNullable(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? self::parse($value) : null;
    }
}
