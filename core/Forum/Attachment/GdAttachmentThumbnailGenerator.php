<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use RuntimeException;

final readonly class GdAttachmentThumbnailGenerator implements AttachmentThumbnailGenerator
{
    public function __construct(private AttachmentQuotaPolicy $policy)
    {
    }

    public function generate(AttachmentInspection $inspection): ?AttachmentThumbnail
    {
        if (!$inspection->isImage()
            || $inspection->imageWidth === null
            || $inspection->imageHeight === null
            || !function_exists('imagecreatefromstring')
            || !function_exists('imagecreatetruecolor')
        ) {
            return null;
        }

        $source = @imagecreatefromstring($inspection->contents);
        if ($source === false) {
            throw new RuntimeException('Attachment thumbnail source could not be decoded.');
        }

        $ratio = min(
            1.0,
            $this->policy->thumbnailMaxWidth / $inspection->imageWidth,
            $this->policy->thumbnailMaxHeight / $inspection->imageHeight,
        );
        $width = max(1, (int) floor($inspection->imageWidth * $ratio));
        $height = max(1, (int) floor($inspection->imageHeight * $ratio));
        $thumbnail = imagecreatetruecolor($width, $height);
        if ($thumbnail === false) {
            imagedestroy($source);
            throw new RuntimeException('Attachment thumbnail canvas could not be created.');
        }

        $transparentOutput = $inspection->mediaType !== 'image/jpeg';
        if ($transparentOutput) {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 0, 0, 0, 127);
            imagefill($thumbnail, 0, 0, $transparent);
        }

        $resampled = imagecopyresampled(
            $thumbnail,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $inspection->imageWidth,
            $inspection->imageHeight,
        );
        imagedestroy($source);
        if (!$resampled) {
            imagedestroy($thumbnail);
            throw new RuntimeException('Attachment thumbnail could not be resampled.');
        }

        ob_start();
        $written = $transparentOutput
            ? (function_exists('imagepng') && imagepng($thumbnail, null, 6))
            : (function_exists('imagejpeg') && imagejpeg($thumbnail, null, 82));
        $contents = ob_get_clean();
        imagedestroy($thumbnail);
        if (!$written || !is_string($contents) || $contents === '') {
            throw new RuntimeException('Attachment thumbnail encoding failed.');
        }

        return new AttachmentThumbnail(
            $contents,
            $transparentOutput ? 'image/png' : 'image/jpeg',
            $transparentOutput ? 'png' : 'jpg',
            $width,
            $height,
        );
    }
}
