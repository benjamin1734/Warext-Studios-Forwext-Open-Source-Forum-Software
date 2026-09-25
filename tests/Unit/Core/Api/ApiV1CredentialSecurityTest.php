<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Api;

use DateInterval;
use DateTimeImmutable;
use Forwext\Core\Api\V1\ApiV1Scope;
use Forwext\Core\Api\V1\Security\ApiV1AuthenticationException;
use Forwext\Core\Api\V1\Security\ApiV1CredentialRecord;
use Forwext\Core\Api\V1\Security\ApiV1CredentialRepository;
use Forwext\Core\Api\V1\Security\ApiV1CredentialResolver;
use Forwext\Core\Api\V1\Security\ApiV1CredentialService;
use Forwext\Core\Api\V1\Security\ApiV1PrincipalType;
use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\HeaderBag;
use Forwext\Core\Http\HttpMethod;
use Forwext\Core\Http\Request;
use Forwext\Core\Infrastructure\Clock;
use PHPUnit\Framework\TestCase;

final class ApiV1CredentialSecurityTest extends TestCase
{
    public function testPersonalTokenIsStoredAsDigestAndResolvesToScopedPrincipal(): void
    {
        $clock = new ApiClockFixture(new DateTimeImmutable('2026-09-25T18:00:00Z'));
        $repo = new ApiCredentialRepositoryFixture();
        $service = new ApiV1CredentialService($repo, $clock);
        $issued = $service->issue(
            EntityId::fromString(str_repeat('1', 32)),
            ApiV1PrincipalType::PersonalToken,
            'CLI token',
            [ApiV1Scope::UsersRead, ApiV1Scope::NotificationsRead],
            new DateInterval('P30D'),
        );

        self::assertStringStartsWith('fxpat_', $issued->secret);
        self::assertNotSame($issued->secret, $issued->record->secretHash);
        self::assertSame(hash('sha256', $issued->secret), $issued->record->secretHash);

        $resolver = new ApiV1CredentialResolver($repo, $clock);
        $principal = $resolver->resolve(new Request(
            HttpMethod::Get,
            '/api/v1/notifications',
            new HeaderBag(['Authorization'=>'Bearer ' . $issued->secret]),
        ));

        self::assertNotNull($principal);
        self::assertSame(ApiV1PrincipalType::PersonalToken, $principal->type);
        self::assertTrue($principal->hasScope(ApiV1Scope::NotificationsRead));
        self::assertFalse($principal->hasScope(ApiV1Scope::SupportRead));
        self::assertSame($issued->record->credentialId->value(), $repo->lastUsedCredential?->value());
    }

    public function testApiKeyUsesDedicatedHeaderAndWrongPresentationFailsClosed(): void
    {
        $clock = new ApiClockFixture(new DateTimeImmutable('2026-09-25T18:00:00Z'));
        $repo = new ApiCredentialRepositoryFixture();
        $issued = (new ApiV1CredentialService($repo, $clock))->issue(
            EntityId::fromString(str_repeat('2', 32)),
            ApiV1PrincipalType::ApiKey,
            'Integration key',
            [ApiV1Scope::MarketplaceRead],
        );
        $resolver = new ApiV1CredentialResolver($repo, $clock);

        self::assertNotNull($resolver->resolve(new Request(
            HttpMethod::Get,
            '/api/v1/marketplace',
            new HeaderBag(['X-API-Key'=>$issued->secret]),
        )));
        self::assertNull($resolver->resolve(new Request(
            HttpMethod::Get,
            '/api/v1/marketplace',
            new HeaderBag(['Authorization'=>'Bearer ' . $issued->secret]),
        )));
    }

    public function testMultipleCredentialsAreRejectedAndExpiredCredentialsDoNotResolve(): void
    {
        $clock = new ApiClockFixture(new DateTimeImmutable('2026-09-25T18:00:00Z'));
        $repo = new ApiCredentialRepositoryFixture();
        $issued = (new ApiV1CredentialService($repo, $clock))->issue(
            EntityId::fromString(str_repeat('3', 32)),
            ApiV1PrincipalType::OAuth,
            'OAuth access token',
            [ApiV1Scope::ConversationsRead],
            new DateInterval('PT1H'),
        );
        $resolver = new ApiV1CredentialResolver($repo, $clock);

        $this->expectException(ApiV1AuthenticationException::class);
        $resolver->resolve(new Request(
            HttpMethod::Get,
            '/api/v1/conversations',
            new HeaderBag([
                'Authorization'=>'Bearer ' . $issued->secret,
                'X-API-Key'=>'fxkey_' . str_repeat('A', 43),
            ]),
        ));
    }

    public function testExpiredCredentialFailsClosed(): void
    {
        $clock = new ApiClockFixture(new DateTimeImmutable('2026-09-25T18:00:00Z'));
        $repo = new ApiCredentialRepositoryFixture();
        $issued = (new ApiV1CredentialService($repo, $clock))->issue(
            EntityId::fromString(str_repeat('4', 32)),
            ApiV1PrincipalType::OAuth,
            'Short OAuth token',
            [ApiV1Scope::ConversationsRead],
            new DateInterval('PT1H'),
        );
        $clock->time = new DateTimeImmutable('2026-09-25T19:00:01Z');

        self::assertNull((new ApiV1CredentialResolver($repo, $clock))->resolve(new Request(
            HttpMethod::Get,
            '/api/v1/conversations',
            new HeaderBag(['Authorization'=>'Bearer ' . $issued->secret]),
        )));
    }
}

final class ApiCredentialRepositoryFixture implements ApiV1CredentialRepository
{
    /** @var array<string,ApiV1CredentialRecord> */
    private array $records = [];
    public ?EntityId $lastUsedCredential = null;

    public function findBySecretHash(string $secretHash): ?ApiV1CredentialRecord
    {
        return $this->records[$secretHash] ?? null;
    }

    public function save(ApiV1CredentialRecord $record): void
    {
        $this->records[$record->secretHash] = $record;
    }

    public function markUsed(EntityId $credentialId, DateTimeImmutable $at): void
    {
        $this->lastUsedCredential = $credentialId;
    }

    public function revoke(EntityId $credentialId, EntityId $ownerUserId, DateTimeImmutable $at): void
    {
    }
}

final class ApiClockFixture implements Clock
{
    public function __construct(public DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
