<?php

declare(strict_types=1);

namespace Forwext\Core\Capability;

use Forwext\Core\Database\CompiledQuery;
use Forwext\Core\Database\QueryExecutor;

final readonly class DatabaseServerCapabilityProbe
{
    public function __construct(private QueryExecutor $database)
    {
    }

    public function probe(): DatabaseServerCapabilityResult
    {
        $row = $this->database->fetchOne(new CompiledQuery(
            'SELECT VERSION() AS `version`, @@version_comment AS `version_comment`',
        ));
        if ($row === null || !is_string($row['version'] ?? null) || !is_string($row['version_comment'] ?? null)) {
            return new DatabaseServerCapabilityResult('unknown', 'unknown', false, false);
        }

        $version = trim($row['version']);
        $comment = trim($row['version_comment']);
        $identity = strtolower($version . ' ' . $comment);
        $vendor = str_contains($identity, 'mariadb') ? 'mariadb' : (str_contains($identity, 'mysql') ? 'mysql' : 'unknown');
        if ($vendor === 'unknown' && preg_match('/^[0-9]+\.[0-9]+/', $version) === 1) {
            $vendor = 'mysql';
        }

        $numericVersion = self::numericVersion($version);
        $fulltext = match ($vendor) {
            'mariadb' => $numericVersion !== null && version_compare($numericVersion, '10.0.5', '>='),
            'mysql' => $numericVersion !== null && version_compare($numericVersion, '5.6.4', '>='),
            default => false,
        };

        return new DatabaseServerCapabilityResult(
            $vendor,
            $version,
            $vendor !== 'unknown',
            $fulltext,
        );
    }

    private static function numericVersion(string $version): ?string
    {
        if (preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
