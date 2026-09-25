<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Forwext\Core\Admin\Integration\GeneratedConfigStore;
use Forwext\Core\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class GeneratedConfigStoreTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-generated-config-' . bin2hex(random_bytes(6));
        $this->path = $this->directory . '/generated.php';
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '.lock'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
    }

    public function testSetDeleteAndNestedMergeAreAtomicAndPersistent(): void
    {
        $store = new GeneratedConfigStore($this->path);

        $store->set('mail.driver', 'smtp');
        $store->set('mail.smtp.port', 587);
        $store->set('realtime.mode', 'sse');

        self::assertSame([
            'mail'=>[
                'driver'=>'smtp',
                'smtp'=>['port'=>587],
            ],
            'realtime'=>['mode'=>'sse'],
        ], $store->all());
        self::assertFileExists($this->path . '.lock');

        $store->delete('mail.smtp.port');
        self::assertSame([
            'mail'=>['driver'=>'smtp'],
            'realtime'=>['mode'=>'sse'],
        ], $store->all());

        $loaded = (static fn (string $path): mixed => require $path)($this->path);
        self::assertIsArray($loaded);
        self::assertSame('smtp', $loaded['mail']['driver'] ?? null);
    }

    public function testInvalidGeneratedPathFailsClosed(): void
    {
        $store = new GeneratedConfigStore($this->path);

        $this->expectException(ConfigException::class);
        $store->set('../security.master_key', 'unsafe');
    }
}
