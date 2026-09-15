<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use RuntimeException;
use Throwable;

final readonly class AttachmentInspector
{
    public function __construct(
        private ImageMetadataSanitizer $sanitizer,
        private AttachmentQuotaPolicy $policy,
    ) {
    }

    public function inspect(string $contents): AttachmentInspection
    {
        try {
            $size = strlen($contents);
            if ($size < 1 || $size > $this->policy->maxFileBytes) {
                throw new RuntimeException('Attachment exceeds the per-file size limit.');
            }
            [$mediaType, $extension] = $this->detect($contents);
            $sanitized = $this->sanitizer->sanitize($contents, $mediaType);
            $contents = $sanitized['contents'];
            $width = null;
            $height = null;
            if (str_starts_with($mediaType, 'image/')) {
                $image = @getimagesizefromstring($contents);
                if (!is_array($image)) {
                    throw new RuntimeException('Attachment image payload is invalid.');
                }
                $width = (int) ($image[0] ?? 0);
                $height = (int) ($image[1] ?? 0);
                if ($width < 1 || $height < 1 || ($width * $height) > $this->policy->maxImagePixels) {
                    throw new RuntimeException('Attachment image dimensions exceed the safety policy.');
                }
                $reportedMime = $image['mime'] ?? null;
                if (!is_string($reportedMime) || $reportedMime !== $mediaType) {
                    throw new RuntimeException('Attachment image signature and decoded media type do not agree.');
                }
            }
            return new AttachmentInspection(
                $contents,
                $mediaType,
                $extension,
                strlen($contents),
                hash('sha256', $contents),
                $width,
                $height,
                $sanitized['stripped'],
            );
        } catch (AttachmentOperationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AttachmentOperationException('Attachment payload failed validation.', previous: $exception);
        }
    }

    /** @return array{string,string} */
    private function detect(string $contents): array
    {
        if (str_starts_with($contents, "\xFF\xD8\xFF")) return ['image/jpeg', 'jpg'];
        if (str_starts_with($contents, "\x89PNG\r\n\x1A\n")) return ['image/png', 'png'];
        if (str_starts_with($contents, 'GIF87a') || str_starts_with($contents, 'GIF89a')) return ['image/gif', 'gif'];
        if (strlen($contents) >= 12 && substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WEBP') return ['image/webp', 'webp'];
        if (str_starts_with($contents, '%PDF-')) return ['application/pdf', 'pdf'];
        if (str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, "PK\x05\x06") || str_starts_with($contents, "PK\x07\x08")) return ['application/zip', 'zip'];
        if (preg_match('//u', $contents) === 1 && !str_contains($contents, "\0")
            && preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents) !== 1) {
            return ['text/plain', 'txt'];
        }
        throw new RuntimeException('Attachment media type is not allowed or its signature is unknown.');
    }
}
