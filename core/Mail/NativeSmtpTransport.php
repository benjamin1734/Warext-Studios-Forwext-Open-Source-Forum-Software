<?php

declare(strict_types=1);

namespace Forwext\Core\Mail;

use Forwext\Core\Domain\User\EmailAddress;
use InvalidArgumentException;

final readonly class NativeSmtpTransport implements MailTransport
{
    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private ?string $username,
        private ?string $password,
        private EmailAddress $fromAddress,
        private string $fromName,
        private int $timeoutSeconds = 10,
    ) {
        self::assertHost($host);
        if ($port < 1 || $port > 65535 || !in_array($encryption, ['none', 'starttls', 'tls'], true)) {
            throw new InvalidArgumentException('SMTP connection configuration is invalid.');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw new InvalidArgumentException('SMTP timeout is invalid.');
        }
        if ($username !== null && ($username === '' || strlen($username) > 254 || preg_match('/[\x00-\x1F\x7F]/', $username) === 1)) {
            throw new InvalidArgumentException('SMTP username is invalid.');
        }
        if ($password !== null && ($password === '' || strlen($password) > 16384 || str_contains($password, "\0"))) {
            throw new InvalidArgumentException('SMTP password is invalid.');
        }
        if (($username === null) !== ($password === null)) {
            throw new InvalidArgumentException('SMTP authentication credentials are incomplete.');
        }
        if ($username !== null && $encryption === 'none') {
            throw new InvalidArgumentException('SMTP authentication requires transport encryption.');
        }
    }

    public function available(): bool
    {
        return function_exists('stream_socket_client')
            && ($this->encryption === 'none' || extension_loaded('openssl'));
    }

    public function send(MailMessage $message): void
    {
        if (!$this->available()) {
            throw new MailException('SMTP transport is unavailable.');
        }

        $socket = $this->connect();
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . $this->clientName(), [250]);

            if ($this->encryption === 'starttls') {
                $this->command($socket, 'STARTTLS', [220]);
                $enabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($enabled !== true) {
                    throw new MailException('SMTP STARTTLS negotiation failed.');
                }
                $this->command($socket, 'EHLO ' . $this->clientName(), [250]);
            }

            if ($this->username !== null && $this->password !== null) {
                $this->authenticate($socket);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress->value() . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $message->recipient->value() . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $payload = MailMessageFormatter::forSmtp($message, $this->fromAddress, $this->fromName);
            $payload = preg_replace('/(?m)^\./', '..', $payload);
            if (!is_string($payload)) {
                throw new MailException('SMTP message encoding failed.');
            }
            $this->write($socket, rtrim($payload, "\r\n") . "\r\n.\r\n");
            $this->expect($socket, [250]);
            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    /** @return resource */
    private function connect()
    {
        $host = filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $this->host . ']'
            : $this->host;
        $scheme = $this->encryption === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'peer_name' => $this->host,
                'SNI_enabled' => true,
            ],
        ]);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            $scheme . '://' . $host . ':' . $this->port,
            $errno,
            $error,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($socket)) {
            throw new MailException('SMTP connection failed.');
        }
        stream_set_timeout($socket, $this->timeoutSeconds);

        return $socket;
    }

    /** @param resource $socket */
    private function authenticate($socket): void
    {
        $plain = base64_encode("\0" . $this->username . "\0" . $this->password);
        $code = $this->command($socket, 'AUTH PLAIN ' . $plain, [235, 334, 500, 501, 502, 504], false);
        if ($code === 235) {
            return;
        }
        if ($code === 334) {
            $this->command($socket, $plain, [235]);
            return;
        }

        $this->command($socket, 'AUTH LOGIN', [334]);
        $this->command($socket, base64_encode((string) $this->username), [334]);
        $this->command($socket, base64_encode((string) $this->password), [235]);
    }

    /** @param resource $socket @param list<int> $expected */
    private function command($socket, string $command, array $expected, bool $strict = true): int
    {
        if (preg_match('/[\r\n\x00]/', $command) === 1) {
            throw new MailException('SMTP command is invalid.');
        }
        $this->write($socket, $command . "\r\n");
        $code = $this->readReply($socket);
        if (!in_array($code, $expected, true) && $strict) {
            throw new MailException('SMTP server rejected a command.');
        }
        return $code;
    }

    /** @param resource $socket @param list<int> $expected */
    private function expect($socket, array $expected): void
    {
        $code = $this->readReply($socket);
        if (!in_array($code, $expected, true)) {
            throw new MailException('SMTP server returned an unexpected response.');
        }
    }

    /** @param resource $socket */
    private function readReply($socket): int
    {
        $code = null;
        $bytes = 0;
        for ($lines = 0; $lines < 100; ++$lines) {
            $line = fgets($socket, 4096);
            if (!is_string($line)) {
                throw new MailException('SMTP response could not be read.');
            }
            $bytes += strlen($line);
            if ($bytes > 65536 || preg_match('/^(\d{3})([ -])/', $line, $matches) !== 1) {
                throw new MailException('SMTP response is malformed.');
            }
            $current = (int) $matches[1];
            $code ??= $current;
            if ($current !== $code) {
                throw new MailException('SMTP multiline response is inconsistent.');
            }
            if ($matches[2] === ' ') {
                return $code;
            }
        }

        throw new MailException('SMTP response exceeded safe limits.');
    }

    /** @param resource $socket */
    private function write($socket, string $payload): void
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($socket, substr($payload, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new MailException('SMTP write failed.');
            }
            $offset += $written;
        }
    }

    private function clientName(): string
    {
        $name = gethostname();
        if (!is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D', $name) !== 1) {
            return 'localhost';
        }
        return $name;
    }

    private static function assertHost(string $host): void
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/[\x00-\x20\x7F]/', $host) === 1) {
            throw new InvalidArgumentException('SMTP host is invalid.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost') {
            return;
        }
        if (preg_match('/^(?=.{1,253}\z)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host) !== 1) {
            throw new InvalidArgumentException('SMTP host is invalid.');
        }
    }
}
