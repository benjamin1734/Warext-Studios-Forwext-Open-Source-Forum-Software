<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

use InvalidArgumentException;

final readonly class EditorPreviewService
{
    public function __construct(
        private BbCodeRenderer $renderer,
        private EditorLimits $limits,
    ) {
    }

    public function preview(string $source): EditorPreview
    {
        if (strlen($source) > $this->limits->maxBytes) {
            throw new InvalidArgumentException('Editor preview source exceeds the configured byte limit.');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $source) === 1) {
            throw new InvalidArgumentException('Editor preview source contains unsupported control characters.');
        }

        $assessment = EditorAssessment::assess($source, $this->limits);
        return new EditorPreview($this->renderer->render($source), $assessment);
    }

    public function limits(): EditorLimits
    {
        return $this->limits;
    }
}
