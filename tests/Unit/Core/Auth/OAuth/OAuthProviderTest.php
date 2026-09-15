<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Auth\OAuth;

use Forwext\Core\Auth\OAuth\DiscordOAuthProvider;
use Forwext\Core\Auth\OAuth\GoogleOAuthProvider;
use Forwext\Core\Auth\OAuth\OAuthHttpClient;
use Forwext\Core\Auth\OAuth\OAuthProviderConfig;
use Forwext\Core\Auth\OAuth\Pkce;
use PHPUnit\Framework\TestCase;

final class OAuthProviderTest extends TestCase
{
    public function testPkceAndGoogleProviderUseStateAndS256(): void
    {
        $verifier = str_repeat('A', 43);
        $challenge = Pkce::challenge($verifier);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $challenge,
        );

        $provider = new GoogleOAuthProvider();
        $config = new OAuthProviderConfig(
            'google',
            true,
            'google-client',
            'oauth.google.client_secret',
            ['https://forum.example.test/oauth/google/callback'],
        );
        $url = $provider->authorizationUrl(
            $config,
            'https://forum.example.test/oauth/google/callback',
            str_repeat('s', 32),
            $challenge,
        );
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertSame($challenge, $query['code_challenge'] ?? null);
        self::assertSame(str_repeat('s', 32), $query['state'] ?? null);
    }

    public function testGoogleMapsVerifiedIdentityWithoutPersistingToken(): void
    {
        $http = new RecordingOAuthHttpClient(
            ['access_token' => 'transient-google-token'],
            ['sub' => 'google-subject', 'email' => 'User@Example.com', 'email_verified' => true, 'name' => 'Example User'],
        );
        $provider = new GoogleOAuthProvider();
        $config = new OAuthProviderConfig(
            'google', true, 'client', 'oauth.google.client_secret', ['https://forum.example.test/oauth/google/callback'],
        );
        $identity = $provider->resolveIdentity(
            $config,
            'secret',
            'https://forum.example.test/oauth/google/callback',
            'authorization-code',
            str_repeat('V', 43),
            $http,
        );
        self::assertSame('google-subject', $identity->subject);
        self::assertSame('User@Example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('transient-google-token', $http->seenBearerToken);
    }

    public function testDiscordMapsVerificationFlagAndDisplayName(): void
    {
        $http = new RecordingOAuthHttpClient(
            ['access_token' => 'transient-discord-token'],
            ['id' => '1234567890', 'email' => 'member@example.com', 'verified' => true, 'global_name' => 'Member'],
        );
        $provider = new DiscordOAuthProvider();
        $config = new OAuthProviderConfig(
            'discord', true, 'client', 'oauth.discord.client_secret', ['https://forum.example.test/oauth/discord/callback'],
        );
        $identity = $provider->resolveIdentity(
            $config,
            'secret',
            'https://forum.example.test/oauth/discord/callback',
            'authorization-code',
            str_repeat('D', 43),
            $http,
        );
        self::assertSame('discord', $identity->providerId);
        self::assertSame('1234567890', $identity->subject);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Member', $identity->displayName);
    }
}

final class RecordingOAuthHttpClient implements OAuthHttpClient
{
    public ?string $seenBearerToken = null;

    /** @param array<string,mixed> $tokenResponse @param array<string,mixed> $profileResponse */
    public function __construct(private array $tokenResponse, private array $profileResponse)
    {
    }

    public function postForm(string $url, array $form): array
    {
        self::assertNotEmptyForTest($form['code_verifier'] ?? null);
        return $this->tokenResponse;
    }

    public function getBearerJson(string $url, string $accessToken): array
    {
        $this->seenBearerToken = $accessToken;
        return $this->profileResponse;
    }

    private static function assertNotEmptyForTest(mixed $value): void
    {
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Test OAuth client expected a PKCE verifier.');
        }
    }
}
