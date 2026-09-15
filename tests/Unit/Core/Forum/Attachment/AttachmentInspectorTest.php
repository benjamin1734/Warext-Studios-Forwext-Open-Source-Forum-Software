<?php

declare(strict_types=1);

namespace Forwext\Tests\Unit\Core\Forum\Attachment;

use Forwext\Core\Forum\Attachment\AttachmentInspector;
use Forwext\Core\Forum\Attachment\AttachmentOperationException;
use Forwext\Core\Forum\Attachment\AttachmentQuotaPolicy;
use Forwext\Core\Forum\Attachment\ImageMetadataSanitizer;
use PHPUnit\Framework\TestCase;

final class AttachmentInspectorTest extends TestCase
{
    public function testPlainTextIsDetectedFromBytesNotClientMetadata(): void
    {
        $result = $this->inspector()->inspect("Merhaba Forwext\n");
        self::assertSame('text/plain', $result->mediaType);
        self::assertSame('txt', $result->extension);
        self::assertSame(hash('sha256', $result->contents), $result->sha256);
    }

    public function testUnknownBinaryFailsClosed(): void
    {
        $this->expectException(AttachmentOperationException::class);
        $this->inspector()->inspect("\x00\x01\x02\x03\x04");
    }

    public function testPngMustDecodeAndSignatureMustAgree(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2nNwAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $result = $this->inspector()->inspect($png);
        self::assertSame('image/png', $result->mediaType);
        self::assertSame(1, $result->imageWidth);
        self::assertSame(1, $result->imageHeight);
    }

    public function testJpegPrivacySegmentsAreRemovedBeforeStorageIdentity(): void
    {
        $segment = static fn (int $marker, string $body): string => "\xFF" . chr($marker) . pack('n', strlen($body) + 2) . $body;
        $jpeg = "\xFF\xD8"
            . $segment(0xE1, "Exif\x00\x00private")
            . $segment(0xED, 'Photoshop private')
            . $segment(0xFE, 'camera comment')
            . "\xFF\xDA\x00\x02payload";

        $sanitized = (new ImageMetadataSanitizer())->sanitize($jpeg, 'image/jpeg');
        self::assertTrue($sanitized['stripped']);
        self::assertStringNotContainsString('private', $sanitized['contents']);
        self::assertStringNotContainsString('camera comment', $sanitized['contents']);
        self::assertStringContainsString('payload', $sanitized['contents']);
    }

    private function inspector(): AttachmentInspector
    {
        return new AttachmentInspector(new ImageMetadataSanitizer(), new AttachmentQuotaPolicy());
    }
}
