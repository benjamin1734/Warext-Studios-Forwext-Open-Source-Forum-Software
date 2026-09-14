<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Http;

use Forwext\Core\Http\HttpException;
use Forwext\Core\Http\Upload\UploadedFile;
use Forwext\Core\Http\Upload\UploadNormalizer;
use PHPUnit\Framework\TestCase;

final class UploadNormalizerTest extends TestCase
{
    public function testNestedPhpFilesShapeIsNormalized(): void
    {
        $normalized = (new UploadNormalizer())->normalize([
            'attachments' => [
                'name' => ['first' => 'a.txt', 'second' => 'b.txt'],
                'type' => ['first' => 'text/plain', 'second' => 'text/plain'],
                'tmp_name' => ['first' => '/tmp/php-a', 'second' => '/tmp/php-b'],
                'error' => ['first' => UPLOAD_ERR_OK, 'second' => UPLOAD_ERR_NO_FILE],
                'size' => ['first' => 12, 'second' => 0],
            ],
        ]);

        self::assertInstanceOf(UploadedFile::class, $normalized['attachments']['first']);
        self::assertSame('a.txt', $normalized['attachments']['first']->clientFilename);
        self::assertTrue($normalized['attachments']['first']->isSuccessful());
        self::assertFalse($normalized['attachments']['second']->isSuccessful());
    }

    public function testMismatchedNestedUploadShapeIsRejected(): void
    {
        $this->expectException(HttpException::class);

        (new UploadNormalizer())->normalize([
            'file' => [
                'name' => ['a' => 'x.txt'],
                'type' => [],
                'tmp_name' => ['a' => '/tmp/x'],
                'error' => ['a' => UPLOAD_ERR_OK],
                'size' => ['a' => 1],
            ],
        ]);
    }
}
