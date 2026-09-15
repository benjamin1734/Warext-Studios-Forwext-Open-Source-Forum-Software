<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth;

use Closure;
use DateTimeImmutable;
use Forwext\Core\Auth\Mfa\Passkey\DatabasePasskeyService;
use Forwext\Core\Auth\Mfa\Passkey\VerifiedPasskeyCredential;
use Forwext\Core\Auth\Mfa\Passkey\WebAuthnEngine;
use Forwext\Core\Auth\Mfa\Policy\MfaPolicy;
use Forwext\Core\Auth\Mfa\Totp\Base32;
use Forwext\Core\Auth\Mfa\Totp\Totp;
use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\TransactionalQueryExecutor;
use Forwext\Core\Domain\Entity\EntityId;
use PHPUnit\Framework\TestCase;

final class MfaRuntimeTest extends TestCase
{
    public function testTotpMatchesRfc6238Sha1VectorAndBase32RoundTrips(): void
    {
        $secret = '12345678901234567890';
        self::assertSame($secret, Base32::decode(Base32::encode($secret)));
        self::assertSame('94287082', Totp::code($secret, 1, 8));
        self::assertSame(1, Totp::matchingCounter($secret, '94287082', 59, 30, 1, 8));
    }

    public function testStrictestMfaGroupPolicyCannotBeWeakenedByAnotherGroup(): void
    {
        $left = new MfaPolicy(loginRequired: true, sensitiveActionRequired: false, trustedDeviceMayBypassLogin: true);
        $right = new MfaPolicy(loginRequired: false, sensitiveActionRequired: true, trustedDeviceMayBypassLogin: false);
        $merged = $left->mergeStrictest($right);

        self::assertTrue($merged->loginRequired);
        self::assertTrue($merged->sensitiveActionRequired);
        self::assertFalse($merged->trustedDeviceMayBypassLogin);
    }

    public function testPasskeyCeremonyIsStoredHashOnlyAndConsumedOnce(): void
    {
        $database = new PasskeyMemoryDatabase();
        $service = new DatabasePasskeyService($database, new FakeWebAuthnEngine());
        $userId = EntityId::fromString(str_repeat('a', 32));

        $ceremony = $service->beginRegistration($userId, 'user', 'User');
        self::assertStringStartsWith('wa_', $ceremony->token);
        self::assertFalse(str_contains(json_encode($database->ceremonies), $ceremony->token));

        $service->completeRegistration($userId, $ceremony->token, '{"ok":true}', 'Laptop');
        self::assertCount(1, $database->credentials);

        $this->expectException(\Forwext\Core\Auth\Mfa\MfaException::class);
        $service->completeRegistration($userId, $ceremony->token, '{"ok":true}', 'Laptop');
    }
}

final class FakeWebAuthnEngine implements WebAuthnEngine
{
    public function registrationOptions(string $userHandle, string $username, string $displayName, array $excludedCredentialIds = []): string { return '{"challenge":"registration"}'; }
    public function verifyRegistration(string $responseJson, string $optionsJson): VerifiedPasskeyCredential { return new VerifiedPasskeyCredential('credential-id', '{"record":1}'); }
    public function authenticationOptions(array $credentialRecordJsons): string { return '{"challenge":"authentication"}'; }
    public function credentialIdFromResponse(string $responseJson): string { return 'credential-id'; }
    public function verifyAuthentication(string $responseJson, string $optionsJson, string $credentialRecordJson, string $expectedUserHandle): string { return '{"record":2}'; }
}

final class PasskeyMemoryDatabase implements TransactionalQueryExecutor
{
    /** @var array<string,array<string,mixed>> */
    public array $ceremonies = [];
    /** @var array<string,array<string,mixed>> */
    public array $credentials = [];

    public function execute(CompiledQuery $query): int
    {
        if (str_contains($query->sql, 'INSERT INTO `forwext_webauthn_ceremonies`')) {
            $this->ceremonies[(string) $query->parameters['ceremony_hash']] = [
                'user_id' => $query->parameters['user_id'],
                'purpose' => $query->parameters['purpose'],
                'options_json' => $query->parameters['options_json'],
                'expires_at_utc' => $query->parameters['expires_at_utc'],
                'consumed_at_utc' => null,
            ];
            return 1;
        }
        if (str_contains($query->sql, 'UPDATE `forwext_webauthn_ceremonies`')) {
            $hash = (string) $query->parameters['ceremony_hash'];
            if (!isset($this->ceremonies[$hash]) || $this->ceremonies[$hash]['consumed_at_utc'] !== null) {
                return 0;
            }
            $this->ceremonies[$hash]['consumed_at_utc'] = $query->parameters['consumed_at_utc'];
            return 1;
        }
        if (str_contains($query->sql, 'INSERT INTO `forwext_passkey_credentials`')) {
            $this->credentials[(string) $query->parameters['credential_hash']] = $query->parameters;
            return 1;
        }
        if (str_contains($query->sql, 'UPDATE `forwext_passkey_credentials`')) {
            return 1;
        }
        return 1;
    }

    public function fetchOne(CompiledQuery $query): ?array
    {
        if (str_contains($query->sql, 'FROM `forwext_webauthn_ceremonies`')) {
            $hash = (string) $query->parameters['ceremony_hash'];
            return $this->ceremonies[$hash] ?? null;
        }
        return null;
    }

    public function fetchAll(CompiledQuery $query): array { return []; }
    public function fetchValue(CompiledQuery $query): mixed { return 0; }
    public function inTransaction(): bool { return false; }
    public function transaction(Closure $callback): mixed { return $callback($this); }
}
