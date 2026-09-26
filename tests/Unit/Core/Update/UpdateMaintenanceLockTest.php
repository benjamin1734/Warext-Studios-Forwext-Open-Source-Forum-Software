<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Update;

use DateTimeImmutable;
use DateTimeZone;
use Forwext\Core\Migration\SemanticVersion;
use Forwext\Core\Update\UpdateException;
use Forwext\Core\Update\UpdateMaintenanceLock;
use PHPUnit\Framework\TestCase;

final class UpdateMaintenanceLockTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-update-maintenance-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_file($this->directory . '/maintenance.json')) {
            @unlink($this->directory . '/maintenance.json');
        }
        @rmdir($this->directory);
    }

    public function testLeaseBlocksConcurrentEntryAndOwnerCanRelease(): void
    {
        $lock = new UpdateMaintenanceLock($this->directory . '/maintenance.json');
        $source = SemanticVersion::parse('0.0.7.63-dev');
        $target = SemanticVersion::parse('0.0.7.64-dev');
        $lease = $lock->enter($source, $target, new DateTimeImmutable('2026-09-26 12:00:00', new DateTimeZone('UTC')));

        self::assertTrue($lock->isActive());

        try {
            $lock->enter($source, $target, new DateTimeImmutable('2026-09-26 12:00:01', new DateTimeZone('UTC')));
            self::fail('Expected concurrent update maintenance lock to fail.');
        } catch (UpdateException) {
        }

        $lock->leave($lease);
        self::assertFalse($lock->isActive());
    }
}
