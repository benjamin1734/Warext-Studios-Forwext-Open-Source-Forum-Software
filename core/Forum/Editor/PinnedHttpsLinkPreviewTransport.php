<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final class PinnedHttpsLinkPreviewTransport implements LinkPreviewTransport
{
    public function fetch(
        ApprovedLinkPreviewUrl $url,
        int $maxBytes = 262144,
        int $timeoutSeconds = 4,
    ): LinkPreviewHttpResponse {
        if ($maxBytes < 1024 || $maxBytes > 1048576 || $timeoutSeconds < 1 || $timeoutSeconds > 10) {
            throw new LinkPreviewException('Link preview transport limits are invalid.');
        }

        $lastError = 'connection_failed';
        foreach ($url->addresses as $address) {
            $remote = str_contains($address, ':')
                ? 'tls://[' . $address . ']:' . $url->port
                : 'tls://' . $address . ':' . $url->port;
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => $url->host,
                    'SNI_enabled' => true,
                    'disable_compression' => true,
                ],
            ]);
            $errno = 0;
            $error = '';
            $stream = @stream_socket_client(
                $remote,
                $errno,
                $error,
                (float) $timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (!is_resource($stream)) {
                $lastError = $error === '' ? 'connection_failed' : $error;
                continue;
            }

            try {
                stream_set_timeout($stream, $timeoutSeconds);
                $request = "GET " . $url->requestTarget . " HTTP/1.1\r\n"
                    . 'Host: ' . $url->host . "\r\n"
                    . "User-Agent: Forwext-LinkPreview/1.0\r\n"
                    . "Accept: text/html,application/xhtml+xml;q=0.9\r\n"
                    . "Accept-Encoding: identity\r\n"
                    . "Connection: close\r\n\r\n";
                if (@fwrite($stream, $request) !== strlen($request)) {
                    throw new LinkPreviewException('Link preview request could not be sent.');
                }

                $statusLine = $this->line($stream, 8192);
                if (preg_match('/\AHTTP\/1\.[01]\s+([1-5][0-9]{2})(?:\s|$)/D', $statusLine, $match) !== 1) {
                    throw new LinkPreviewException('Link preview response status is invalid.');
                }
                $status = (int) $match[1];
                $headers = [];
                $headerBytes = strlen($statusLine);
                while (true) {
                    $line = $this->line($stream, 8192);
                    $headerBytes += strlen($line);
                    if ($headerBytes > 65536) {
                        throw new LinkPreviewException('Link preview response headers are too large.');
                    }
                    if ($line === "\r\n" || $line === "\n" || $line === '') {
                        break;
                    }
                    $separator = strpos($line, ':');
                    if ($separator === false) {
                        throw new LinkPreviewException('Link preview response header is invalid.');
                    }
                    $name = strtolower(trim(substr($line, 0, $separator)));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '' && !isset($headers[$name])) {
                        $headers[$name] = $value;
                    }
                }

                $body = '';
                if (!in_array($status, [204, 304], true)) {
                    $transfer = strtolower($headers['transfer-encoding'] ?? '');
                    $body = str_contains($transfer, 'chunked')
                        ? $this->readChunked($stream, $maxBytes)
                        : $this->readBounded($stream, $maxBytes, $headers['content-length'] ?? null);
                }

                $meta = stream_get_meta_data($stream);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new LinkPreviewException('Link preview response timed out.');
                }
                return new LinkPreviewHttpResponse($status, $headers, $body);
            } finally {
                fclose($stream);
            }
        }

        throw new LinkPreviewException('Link preview connection failed: ' . $lastError);
    }

    /** @param resource $stream */
    private function line($stream, int $maxBytes): string
    {
        $line = fgets($stream, $maxBytes + 1);
        if (!is_string($line) || strlen($line) > $maxBytes) {
            throw new LinkPreviewException('Link preview response line is invalid.');
        }
        return $line;
    }

    /** @param resource $stream */
    private function readBounded($stream, int $maxBytes, ?string $contentLength): string
    {
        if ($contentLength !== null && preg_match('/\A[0-9]+\z/D', trim($contentLength)) === 1
            && (int) trim($contentLength) > $maxBytes
        ) {
            throw new LinkPreviewException('Link preview response body exceeds the size limit.');
        }

        $body = '';
        while (!feof($stream)) {
            $chunk = fread($stream, min(8192, $maxBytes + 1 - strlen($body)));
            if ($chunk === false) {
                throw new LinkPreviewException('Link preview response body could not be read.');
            }
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                throw new LinkPreviewException('Link preview response body exceeds the size limit.');
            }
            if ($chunk === '') {
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
            $sizeToken = strtolower(strtok($line, ';') ?: '');
            if ($sizeToken === '' || preg_match('/\A[0-9a-f]+\z/D', $sizeToken) !== 1) {
                throw new LinkPreviewException('Chunked preview response is invalid.');
            }
            $size = hexdec($sizeToken);
            if ($size === 0) {
                while (trim($this->line($stream, 8192)) !== '') {
                    // consume bounded trailer fields
                }
                break;
            }
            if ($size > $maxBytes - strlen($body)) {
                throw new LinkPreviewException('Link preview response body exceeds the size limit.');
            }
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = fread($stream, min(8192, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    throw new LinkPreviewException('Chunked preview response ended unexpectedly.');
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            $ending = fread($stream, 2);
            if ($ending !== "\r\n") {
                throw new LinkPreviewException('Chunked preview response delimiter is invalid.');
            }
        }
        return $body;
    }
}
