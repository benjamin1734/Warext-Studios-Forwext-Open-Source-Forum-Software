<?php

declare(strict_types=1);

namespace Forwext\Core\Database;

use PDO;
use PDOException;

final class PdoConnectionFactory
{
    public function create(DatabaseConfig $config): DatabaseConnection
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseException('PDO MySQL driver is not available.');
        }

        try {
            $pdo = new PDO(
                $config->dsn(),
                $config->username,
                $config->passwordForConnection(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_TIMEOUT => $config->connectTimeoutSeconds,
                    PDO::ATTR_PERSISTENT => false,
                ],
            );
        } catch (PDOException $exception) {
            throw new DatabaseException('Unable to establish the database connection.', previous: $exception);
        }

        return new DatabaseConnection($pdo);
    }
}
