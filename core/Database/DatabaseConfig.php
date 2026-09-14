<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

use SensitiveParameter;

final readonly class DatabaseConfig
{
    private string $password;

    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter] string $password,
        public string $charset = 'utf8mb4',
        public int $connectTimeoutSeconds = 5,
        public ?string $unixSocket = null,
    ) {
        if ($host === '' || preg_match('/[;\x00-\x1F\x7F]/', $host) === 1) {
            throw new DatabaseException('Database host is invalid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new DatabaseException('Database port is out of range.');
        }
        if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $database) !== 1) {
            throw new DatabaseException('Database name contains unsupported characters.');
        }
        if ($username === '' || strlen($username) > 128 || preg_match('/[;\x00-\x1F\x7F]/', $username) === 1) {
            throw new DatabaseException('Database username is invalid.');
        }
        if (str_contains($password, "\0")) {
            throw new DatabaseException('Database password contains a NUL character.');
        }
        if (preg_match('/^[A-Za-z0-9_]{1,32}$/D', $charset) !== 1) {
            throw new DatabaseException('Database charset is invalid.');
        }
        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > 60) {
            throw new DatabaseException('Database connection timeout is out of range.');
        }
        if ($unixSocket !== null && ($unixSocket === '' || str_contains($unixSocket, ';') || str_contains($unixSocket, "\0"))) {
            throw new DatabaseException('Database unix socket path is invalid.');
        }

        $this->password = $password;
    }

    public function dsn(): string
    {
        if ($this->unixSocket !== null) {
            return sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $this->unixSocket,
                $this->database,
                $this->charset,
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset,
        );
    }

    /** @internal Never log or expose this value. */
    public function passwordForConnection(): string
    {
        return $this->password;
    }
}
