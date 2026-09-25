<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Admin;

use Forwext\Core\Admin\Operations\SystemLogReader;
use PHPUnit\Framework\TestCase;

final class SystemLogReaderTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/forwext-log-reader-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700, true));
        $this->path = $this->directory . '/forwext.jsonl';

        $records = [
            ['timestamp'=>'2026-09-25T08:00:00.000000Z','level'=>'info','message'=>'first','context'=>['request_id'=>'a']],
            ['timestamp'=>'2026-09-25T08:01:00.000000Z','level'=>'error','message'=>'second','context'=>['token'=>'secret-token','nested'=>['password'=>'secret-password','safe'=>'ok']]],
            ['timestamp'=>'2026-09-25T08:02:00.000000Z','level'=>'warning','message'=>'third','context'=>[]],
        ];
        $payload = implode(PHP_EOL, array_map(
            static fn (array $record): string => (string) json_encode($record, JSON_THROW_ON_ERROR),
            $records,
        )) . PHP_EOL;
        self::assertNotFalse(file_put_contents($this->path, $payload));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir($this->directory);
    }

    public function testTailIsBoundedNewestFirstAndRedactsSensitiveContext(): void
    {
        $entries = (new SystemLogReader($this->path))->tail(2);

        self::assertCount(2, $entries);
        self::assertSame('third', $entries[0]->message);
        self::assertSame('second', $entries[1]->message);
        self::assertSame('[REDACTED]', $entries[1]->context['token']);
        self::assertSame('[REDACTED]', $entries[1]->context['nested']['password']);
        self::assertSame('ok', $entries[1]->context['nested']['safe']);
    }

    public function testMissingLogFileReturnsEmptyList(): void
    {
        @unlink($this->path);

        self::assertSame([], (new SystemLogReader($this->path))->tail(100));
    }
}
