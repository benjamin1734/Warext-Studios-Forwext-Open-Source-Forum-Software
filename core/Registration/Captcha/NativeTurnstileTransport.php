<?php

declare(strict_types=1);

namespace Forwext\Core\Registration\Captcha;

use Forwext\Core\Registration\RegistrationException;

final readonly class NativeTurnstileTransport implements TurnstileTransport
{
    public function postForm(string $url, array $fields, int $timeoutSeconds = 5): string
    {
        if ($url !== 'https://challenges.cloudflare.com/turnstile/v0/siteverify') {
            throw new RegistrationException('Unexpected Turnstile verification endpoint.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 15) {
            throw new RegistrationException('Turnstile HTTP timeout is invalid.');
        }
        if ((string) ini_get('allow_url_fopen') === '' || ini_get('allow_url_fopen') === '0') {
            throw new RegistrationException('Turnstile native transport requires allow_url_fopen or a custom transport.');
        }

        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nConnection: close\r\n",
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $response = @file_get_contents($url, false, $context, 0, 65536);
        if (!is_string($response)) {
            throw new RegistrationException('Turnstile verification request failed.');
        }

        $status = null;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if ($status !== 200) {
            throw new RegistrationException('Turnstile verification service returned an unexpected status.');
        }
        return $response;
    }
}
