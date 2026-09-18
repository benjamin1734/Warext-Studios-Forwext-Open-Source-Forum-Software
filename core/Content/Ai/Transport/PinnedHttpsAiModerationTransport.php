<?php

declare(strict_types=1);

namespace Forwext\Core\Content\Ai\Transport;

use Forwext\Core\Content\Ai\AiModerationProviderException;
use Forwext\Core\Content\Ai\AiModerationTimeoutException;

final readonly class PinnedHttpsAiModerationTransport implements AiModerationHttpTransport
{
    public function postJson(
        AiModerationEndpoint $endpoint,
        array $headers,
        string $json,
        int $timeoutMilliseconds,
        int $maxResponseBytes = 524288,
    ): AiModerationHttpResponse {
        if ($endpoint->port !== 443
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $endpoint->host) !== 1
            || $endpoint->requestTarget === ''
            || $endpoint->requestTarget[0] !== '/'
            || preg_match('/[\x00-\x20\x7F]/', $endpoint->requestTarget) === 1
            || $endpoint->addresses === []
        ) {
            throw new AiModerationProviderException('AI provider pinned endpoint is invalid.');
        }
        foreach ($endpoint->addresses as $address) {
            if (!is_string($address)
                || filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false
            ) {
                throw new AiModerationProviderException('AI provider pinned endpoint contains a non-public address.');
            }
        }

        if ($timeoutMilliseconds < 250 || $timeoutMilliseconds > 15000
            || $maxResponseBytes < 1024 || $maxResponseBytes > 1048576
            || strlen($json) > 1048576
        ) {
            throw new AiModerationProviderException('AI provider transport limits are invalid.');
        }

        $normalizedHeaders = [
            'Content-Type'=>'application/json',
            'Accept'=>'application/json',
            'Accept-Encoding'=>'identity',
            'User-Agent'=>'Forwext-AIModeration/1.0',
        ];
        foreach ($headers as $name=>$value) {
            if (!is_string($name) || !is_string($value)
                || preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $name) !== 1
                || $value === '' || strlen($value) > 8192
                || preg_match('/[\r\n\x00]/', $value) === 1
            ) {
                throw new AiModerationProviderException('AI provider request header is invalid.');
            }
            $normalizedHeaders[$name] = $value;
        }
        $normalizedHeaders['Content-Length'] = (string) strlen($json);
        $normalizedHeaders['Connection'] = 'close';

        $timeoutSeconds = $timeoutMilliseconds / 1000;
        $lastConnectionFailure = false;

        foreach ($endpoint->addresses as $address) {
            $remote = str_contains($address, ':')
                ? 'tls://[' . $address . ']:' . $endpoint->port
                : 'tls://' . $address . ':' . $endpoint->port;
            $context = stream_context_create([
                'ssl'=>[
                    'verify_peer'=>true,
                    'verify_peer_name'=>true,
                    'peer_name'=>$endpoint->host,
                    'SNI_enabled'=>true,
                    'disable_compression'=>true,
                ],
            ]);
            $errno = 0;
            $error = '';
            $stream = @stream_socket_client(
                $remote,
                $errno,
                $error,
                $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (!is_resource($stream)) {
                $lastConnectionFailure = true;
                continue;
            }

            try {
                $seconds = intdiv($timeoutMilliseconds, 1000);
                $microseconds = ($timeoutMilliseconds % 1000) * 1000;
                stream_set_timeout($stream, $seconds, $microseconds);

                $request = 'POST ' . $endpoint->requestTarget . " HTTP/1.1\r\n"
                    . 'Host: ' . $endpoint->host . "\r\n";
                foreach ($normalizedHeaders as $name=>$value) {
                    $request .= $name . ': ' . $value . "\r\n";
                }
                $request .= "\r\n" . $json;

                $written = 0;
                $length = strlen($request);
                while ($written < $length) {
                    $chunk = @fwrite($stream, substr($request, $written));
                    if (!is_int($chunk) || $chunk < 1) {
                        $this->assertNotTimedOut($stream);
                        throw new AiModerationProviderException('AI provider request could not be sent.');
                    }
                    $written += $chunk;
                }

                $statusLine = $this->line($stream, 8192);
                if (preg_match('/\AHTTP\/1\.[01]\s+([1-5][0-9]{2})(?:\s|$)/D', $statusLine, $match) !== 1) {
                    throw new AiModerationProviderException('AI provider response status is invalid.');
                }
                $status = (int) $match[1];
                $responseHeaders = [];
                $headerBytes = strlen($statusLine);
                while (true) {
                    $line = $this->line($stream, 8192);
                    $headerBytes += strlen($line);
                    if ($headerBytes > 65536) {
                        throw new AiModerationProviderException('AI provider response headers are too large.');
                    }
                    if ($line === "\r\n" || $line === "\n" || $line === '') {
                        break;
                    }
                    $separator = strpos($line, ':');
                    if ($separator === false) {
                        throw new AiModerationProviderException('AI provider response header is invalid.');
                    }
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '' && !isset($responseHeaders[$name])) {
                        $responseHeaders[$name] = $value;
                    }
                }

                $transfer = strtolower($responseHeaders['transfer-encoding'] ?? '');
                $body = str_contains($transfer, 'chunked')
                    ? $this->readChunked($stream, $maxResponseBytes)
                    : $this->readBounded($stream, $maxResponseBytes, $responseHeaders['content-length'] ?? null);
                $this->assertNotTimedOut($stream);

                return new AiModerationHttpResponse($status, $responseHeaders, $body);
            } finally {
                fclose($stream);
            }
        }

        if ($lastConnectionFailure) {
            throw new AiModerationProviderException('AI provider connection failed.');
        }
        throw new AiModerationProviderException('AI provider endpoint has no usable address.');
    }

    /** @param resource $stream */
    private function line($stream, int $maxBytes): string
    {
        $line = fgets($stream, $maxBytes + 1);
        if (!is_string($line)) {
            $this->assertNotTimedOut($stream);
            throw new AiModerationProviderException('AI provider response ended unexpectedly.');
        }
        if (strlen($line) > $maxBytes) {
            throw new AiModerationProviderException('AI provider response line is too large.');
        }
        return $line;
    }

    /** @param resource $stream */
    private function readBounded($stream, int $maxBytes, ?string $contentLength): string
    {
        if ($contentLength !== null) {
            $trimmed = trim($contentLength);
            if (preg_match('/\A[0-9]+\z/D', $trimmed) !== 1 || (int) $trimmed > $maxBytes) {
                throw new AiModerationProviderException('AI provider response body size is invalid.');
            }
        }

        $body = '';
        while (!feof($stream)) {
            $remaining = $maxBytes + 1 - strlen($body);
            $chunk = fread($stream, min(8192, $remaining));
            if ($chunk === false) {
                $this->assertNotTimedOut($stream);
                throw new AiModerationProviderException('AI provider response could not be read.');
            }
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                throw new AiModerationProviderException('AI provider response body exceeds the size limit.');
            }
            if ($chunk === '') {
                $this->assertNotTimedOut($stream);
                break;
            }
        }
        return $body;
    }

    /** @param resource $stream */
    private function readChunked($stream, int $maxBytes): string
    {
        $body = '';
        while (true) {
            $line = trim($this->line($stream, 128));
            $token = strtolower(strtok($line, ';') ?: '');
            if ($token === '' || preg_match('/\A[0-9a-f]+\z/D', $token) !== 1) {
                throw new AiModerationProviderException('AI provider chunked response is invalid.');
            }
            $size = hexdec($token);
            if ($size === 0) {
                while (trim($this->line($stream, 8192)) !== '') {
                    // Consume bounded trailer headers.
                }
                break;
            }
            if ($size > $maxBytes - strlen($body)) {
                throw new AiModerationProviderException('AI provider response body exceeds the size limit.');
            }
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($stream, min(8192, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    $this->assertNotTimedOut($stream);
                    throw new AiModerationProviderException('AI provider chunked response ended unexpectedly.');
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            if (fread($stream, 2) !== "\r\n") {
                throw new AiModerationProviderException('AI provider chunk delimiter is invalid.');
            }
        }
        return $body;
    }

    /** @param resource $stream */
    private function assertNotTimedOut($stream): void
    {
        $meta = stream_get_meta_data($stream);
        if (($meta['timed_out'] ?? false) === true) {
            throw new AiModerationTimeoutException('AI provider request timed out.');
        }
    }
}
