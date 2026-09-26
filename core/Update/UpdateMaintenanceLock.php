<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Migration\SemanticVersion;
use JsonException;

final readonly class UpdateMaintenanceLock
{
    public function __construct(private string $path)
    {
        if ($this->path === '' || str_contains($this->path, "\0")) {
            throw new UpdateException('Update maintenance path is invalid.');
        }
    }

    public function enter(
        SemanticVersion $source,
        SemanticVersion $target,
        DateTimeImmutable $at,
    ): UpdateMaintenanceLease {
        $directory = dirname($this->path);
        if (is_link($directory)) {
            throw new UpdateException('Update maintenance directory may not be a symbolic link.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new UpdateException('Update maintenance directory cannot be created.');
        }
        if (is_link($this->path)) {
            throw new UpdateException('Update maintenance file may not be a symbolic link.');
        }

        $token = bin2hex(random_bytes(16));
        $lease = new UpdateMaintenanceLease($token, $source, $target);
        $payload = json_encode([
            'schema'=>1,
            'token'=>$token,
            'source_version'=>$source->value(),
            'target_version'=>$target->value(),
            'started_at_utc'=>$at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $handle = @fopen($this->path, 'xb');
        if ($handle === false) {
            throw new UpdateException('Another update maintenance session is already active.');
        }
        try {
            @chmod($this->path, 0600);
            if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
                throw new UpdateException('Update maintenance state could not be persisted.');
            }
        } catch (\Throwable $failure) {
            fclose($handle);
            @unlink($this->path);
            throw $failure;
        }
        fclose($handle);

        return $lease;
    }

    public function leave(UpdateMaintenanceLease $lease): void
    {
        if (!is_file($this->path) || is_link($this->path)) {
            throw new UpdateException('Update maintenance state is missing or unsafe.');
        }

        $raw = @file_get_contents($this->path);
        if ($raw === false) {
            throw new UpdateException('Update maintenance state cannot be read.');
        }
        try {
            $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UpdateException('Update maintenance state is corrupt.', previous: $exception);
        }
        if (!is_array($state) || !is_string($state['token'] ?? null) || !hash_equals($lease->token, $state['token'])) {
            throw new UpdateException('Update maintenance lease does not own the active state.');
        }
        if (!@unlink($this->path)) {
            throw new UpdateException('Update maintenance state could not be released.');
        }
    }

    public function isActive(): bool
    {
        return is_file($this->path) && !is_link($this->path);
    }
}
