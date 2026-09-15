<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use RuntimeException;

final class ImageMetadataSanitizer
{
    /** @return array{contents:string,stripped:bool} */
    public function sanitize(string $contents, string $mediaType): array
    {
        return match ($mediaType) {
            'image/jpeg' => $this->jpeg($contents),
            'image/png' => $this->png($contents),
            'image/webp' => $this->webp($contents),
            default => ['contents' => $contents, 'stripped' => false],
        };
    }

    /** @return array{contents:string,stripped:bool} */
    private function jpeg(string $contents): array
    {
        if (!str_starts_with($contents, "\xFF\xD8")) {
            throw new RuntimeException('JPEG signature is invalid.');
        }
        $length = strlen($contents);
        $offset = 2;
        $out = "\xFF\xD8";
        $stripped = false;

        while ($offset < $length) {
            if (ord($contents[$offset]) !== 0xFF) {
                $out .= substr($contents, $offset);
                break;
            }
            $markerStart = $offset;
            while ($offset < $length && ord($contents[$offset]) === 0xFF) {
                ++$offset;
            }
            if ($offset >= $length) {
                throw new RuntimeException('JPEG marker stream is truncated.');
            }
            $marker = ord($contents[$offset]);
            ++$offset;
            if ($marker === 0xD9) {
                $out .= substr($contents, $markerStart, $offset - $markerStart);
                break;
            }
            if ($marker === 0xDA) {
                $out .= substr($contents, $markerStart);
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= substr($contents, $markerStart, $offset - $markerStart);
                continue;
            }
            if ($offset + 2 > $length) {
                throw new RuntimeException('JPEG segment length is truncated.');
            }
            $segmentLength = unpack('n', substr($contents, $offset, 2))[1] ?? 0;
            if ($segmentLength < 2 || $offset + $segmentLength > $length) {
                throw new RuntimeException('JPEG segment length is invalid.');
            }
            $segment = substr($contents, $markerStart, ($offset - $markerStart) + $segmentLength);
            if ($marker === 0xE1 || $marker === 0xED || $marker === 0xFE) {
                $stripped = true;
            } else {
                $out .= $segment;
            }
            $offset += $segmentLength;
        }
        return ['contents' => $out, 'stripped' => $stripped];
    }

    /** @return array{contents:string,stripped:bool} */
    private function png(string $contents): array
    {
        $signature = "\x89PNG\r\n\x1A\n";
        if (!str_starts_with($contents, $signature)) {
            throw new RuntimeException('PNG signature is invalid.');
        }
        $offset = 8;
        $length = strlen($contents);
        $out = $signature;
        $stripped = false;
        $privateChunks = ['eXIf' => true, 'tEXt' => true, 'zTXt' => true, 'iTXt' => true];
        while ($offset < $length) {
            if ($offset + 12 > $length) {
                throw new RuntimeException('PNG chunk stream is truncated.');
            }
            $dataLength = unpack('N', substr($contents, $offset, 4))[1] ?? -1;
            $chunkLength = 12 + $dataLength;
            if ($dataLength < 0 || $offset + $chunkLength > $length) {
                throw new RuntimeException('PNG chunk length is invalid.');
            }
            $type = substr($contents, $offset + 4, 4);
            $chunk = substr($contents, $offset, $chunkLength);
            if (isset($privateChunks[$type])) {
                $stripped = true;
            } else {
                $out .= $chunk;
            }
            $offset += $chunkLength;
            if ($type === 'IEND') {
                break;
            }
        }
        return ['contents' => $out, 'stripped' => $stripped];
    }

    /** @return array{contents:string,stripped:bool} */
    private function webp(string $contents): array
    {
        if (strlen($contents) < 12 || substr($contents, 0, 4) !== 'RIFF' || substr($contents, 8, 4) !== 'WEBP') {
            throw new RuntimeException('WebP signature is invalid.');
        }
        $offset = 12;
        $length = strlen($contents);
        $payload = 'WEBP';
        $stripped = false;
        while ($offset + 8 <= $length) {
            $type = substr($contents, $offset, 4);
            $size = unpack('V', substr($contents, $offset + 4, 4))[1] ?? -1;
            if ($size < 0 || $offset + 8 + $size > $length) {
                throw new RuntimeException('WebP chunk length is invalid.');
            }
            $padded = $size + ($size % 2);
            $chunk = substr($contents, $offset, 8 + $padded);
            if ($type === 'EXIF' || $type === 'XMP ') {
                $stripped = true;
            } else {
                $payload .= $chunk;
            }
            $offset += 8 + $padded;
        }
        return ['contents' => 'RIFF' . pack('V', strlen($payload)) . $payload, 'stripped' => $stripped];
    }
}
