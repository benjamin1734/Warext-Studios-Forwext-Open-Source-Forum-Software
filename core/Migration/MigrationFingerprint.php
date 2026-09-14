<?php

declare(strict_types=1);

namespace Forwext\Core\Migration;

use ReflectionClass;
use ReflectionException;

final class MigrationFingerprint
{
    public static function calculate(Migration $migration): string
    {
        try {
            $reflection = new ReflectionClass($migration);
        } catch (ReflectionException $exception) {
            throw new MigrationIntegrityException('Unable to reflect migration for fingerprinting.', previous: $exception);
        }

        $file = $reflection->getFileName();
        if (!is_string($file) || !is_file($file)) {
            throw new MigrationIntegrityException('Migration source file is unavailable for fingerprinting.');
        }

        $hash = hash_file('sha256', $file);
        if (!is_string($hash)) {
            throw new MigrationIntegrityException('Unable to fingerprint migration source file.');
        }

        return hash('sha256', implode('|', [
            $migration->owner()->key(),
            $migration->id()->value(),
            $migration::class,
            $hash,
        ]));
    }
}
