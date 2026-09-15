<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class EditorPreview
{
    public function __construct(
        public string $html,
        public EditorAssessment $assessment,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'html' => $this->html,
            'valid' => $this->assessment->isValid(),
            'metrics' => [
                'characters' => $this->assessment->metrics->characters,
                'words' => $this->assessment->metrics->words,
                'bytes' => $this->assessment->metrics->bytes,
            ],
            'limits' => [
                'min_characters' => $this->assessment->limits->minCharacters,
                'max_characters' => $this->assessment->limits->maxCharacters,
                'max_bytes' => $this->assessment->limits->maxBytes,
                'min_words' => $this->assessment->limits->minWords,
                'max_words' => $this->assessment->limits->maxWords,
            ],
            'violations' => $this->assessment->violations,
        ];
    }
}
