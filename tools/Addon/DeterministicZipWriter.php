<?php

declare(strict_types=1);

namespace Forwext\Tools\Addon;

use RuntimeException;

final class DeterministicZipWriter
{
    /** @param array<string,string> $files */
    public function write(string $path, array $files): void
    {
        if (count($files) > 65535) {
            throw new RuntimeException('ZIP entry count exceeds the supported limit.');
        }
        ksort($files, SORT_STRING);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ZIP output directory.');
        }
        if (is_link($path)) {
            throw new RuntimeException('ZIP output may not be a symbolic link.');
        }

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open ZIP output.');
        }
        $central = '';
        $entries = 0;
        try {
            foreach ($files as $name=>$data) {
                $this->assertName($name);
                $size = strlen($data);
                $offset = ftell($handle);
                if ($size > 0xffffffff || !is_int($offset) || $offset < 0 || $offset > 0xffffffff) {
                    throw new RuntimeException('ZIP32 limits exceeded.');
                }
                $crc = (int) hexdec(hash('crc32b', $data));
                $nameLength = strlen($name);
                $local = pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,0x21,$crc,$size,$size,$nameLength,0);
                $this->writeAll($handle, $local . $name . $data);
                $central .= pack(
                    'VvvvvvvVVVvvvvvVV',
                    0x02014b50,20,20,0,0,0,0x21,$crc,$size,$size,$nameLength,0,0,0,0,0,$offset,
                ) . $name;
                ++$entries;
            }

            $centralOffset = ftell($handle);
            if (!is_int($centralOffset) || $centralOffset < 0 || $centralOffset > 0xffffffff) {
                throw new RuntimeException('ZIP central directory offset is invalid.');
            }
            $this->writeAll($handle, $central);
            $this->writeAll(
                $handle,
                pack('VvvvvVVv',0x06054b50,0,0,$entries,$entries,strlen($central),$centralOffset,0),
            );
        } finally {
            fclose($handle);
        }
    }

    private function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 1024 || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException('ZIP entry name is invalid.');
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('ZIP entry contains an unsafe path segment.');
            }
        }
    }

    /** @param resource $handle */
    private function writeAll($handle, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($handle, substr($data, $offset));
            if (!is_int($written) || $written < 1) {
                throw new RuntimeException('Unable to write ZIP data.');
            }
            $offset += $written;
        }
    }
}
