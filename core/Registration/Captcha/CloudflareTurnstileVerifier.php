<?php

declare(strict_types=1);

namespace Forwext\Core\Registration\Captcha;

use Forwext\Core\Registration\RegistrationException;
use Forwext\Core\Security\Secret\SecretStore;
use JsonException;
use SensitiveParameter;

final readonly class CloudflareTurnstileVerifier implements CaptchaVerifier
{
    private const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private SecretStore $secrets,
        private TurnstileTransport $transport,
        private string $secretName = 'turnstile.secret',
        private ?string $expectedHostname = null,
        private string $expectedAction = 'register',
    ) {
    }

    public function verify(#[SensitiveParameter] string $token, string $clientIp): CaptchaVerification
    {
        if ($token === '' || strlen($token) > 2048) {
            return new CaptchaVerification(false, ['invalid-input-response']);
        }
        if (filter_var($clientIp, FILTER_VALIDATE_IP) === false) {
            throw new RegistrationException('Turnstile client IP is invalid.');
        }
        $secret = $this->secrets->get($this->secretName);
        if ($secret === null || $secret === '') {
            throw new RegistrationException('Turnstile secret is not configured.');
        }

        $response = $this->transport->postForm(self::ENDPOINT, [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $clientIp,
            'idempotency_key' => self::uuidV4(),
        ]);

        try {
            $data = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RegistrationException('Turnstile verification response is invalid.', previous: $exception);
        }
        if (!is_array($data)) {
            throw new RegistrationException('Turnstile verification response has an invalid shape.');
        }

        $success = ($data['success'] ?? false) === true;
        $hostname = is_string($data['hostname'] ?? null) ? $data['hostname'] : null;
        $action = is_string($data['action'] ?? null) ? $data['action'] : null;
        $errors = [];
        if (is_array($data['error-codes'] ?? null)) {
            foreach ($data['error-codes'] as $error) {
                if (is_string($error) && preg_match('/^[a-z0-9_-]{1,64}$/D', $error) === 1) {
                    $errors[] = $error;
                }
            }
        }

        if ($success && $this->expectedHostname !== null && !hash_equals($this->expectedHostname, (string) $hostname)) {
            $success = false;
            $errors[] = 'hostname-mismatch';
        }
        if ($success && $this->expectedAction !== '' && !hash_equals($this->expectedAction, (string) $action)) {
            $success = false;
            $errors[] = 'action-mismatch';
        }

        return new CaptchaVerification($success, array_values(array_unique($errors)), $hostname, $action);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
