<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Registration;

use Forwext\Core\Registration\Captcha\CloudflareTurnstileVerifier;
use Forwext\Core\Registration\Captcha\TurnstileTransport;
use Forwext\Core\Security\Secret\SecretStore;
use PHPUnit\Framework\TestCase;

final class TurnstileVerifierTest extends TestCase
{
    public function testSuccessfulVerificationSendsServerSecretRemoteIpAndIdempotencyKey(): void
    {
        $transport = new RecordingTurnstileTransport(json_encode([
            'success' => true,
            'hostname' => 'forum.example',
            'action' => 'register',
            'error-codes' => [],
        ], JSON_THROW_ON_ERROR));
        $verifier = new CloudflareTurnstileVerifier(
            new ArraySecretStore(['turnstile.secret' => str_repeat('s', 40)]),
            $transport,
            expectedHostname: 'forum.example',
        );

        $result = $verifier->verify('turnstile-token', '203.0.113.9');

        self::assertTrue($result->success);
        self::assertSame('turnstile-token', $transport->fields['response'] ?? null);
        self::assertSame('203.0.113.9', $transport->fields['remoteip'] ?? null);
        self::assertSame(str_repeat('s', 40), $transport->fields['secret'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            $transport->fields['idempotency_key'] ?? '',
        );
    }

    public function testHostnameAndActionMismatchFailClosed(): void
    {
        $transport = new RecordingTurnstileTransport(json_encode([
            'success' => true,
            'hostname' => 'evil.example',
            'action' => 'login',
        ], JSON_THROW_ON_ERROR));
        $verifier = new CloudflareTurnstileVerifier(
            new ArraySecretStore(['turnstile.secret' => str_repeat('s', 40)]),
            $transport,
            expectedHostname: 'forum.example',
        );

        $result = $verifier->verify('turnstile-token', '2001:db8::1');

        self::assertFalse($result->success);
        self::assertContains('hostname-mismatch', $result->errorCodes);
    }

    public function testOversizedTokenIsRejectedWithoutNetworkCall(): void
    {
        $transport = new RecordingTurnstileTransport('{}');
        $verifier = new CloudflareTurnstileVerifier(
            new ArraySecretStore(['turnstile.secret' => str_repeat('s', 40)]),
            $transport,
        );

        $result = $verifier->verify(str_repeat('x', 2049), '203.0.113.9');

        self::assertFalse($result->success);
        self::assertSame(0, $transport->calls);
    }
}

final class RecordingTurnstileTransport implements TurnstileTransport
{
    /** @var array<string, string> */
    public array $fields = [];
    public int $calls = 0;

    public function __construct(private readonly string $response)
    {
    }

    public function postForm(string $url, array $fields, int $timeoutSeconds = 5): string
    {
        ++$this->calls;
        $this->fields = $fields;
        return $this->response;
    }
}

final class ArraySecretStore implements SecretStore
{
    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function get(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->values[$name] = $value;
    }

    public function delete(string $name): bool
    {
        if (!isset($this->values[$name])) {
            return false;
        }
        unset($this->values[$name]);
        return true;
    }

    public function all(): array
    {
        return $this->values;
    }
}
