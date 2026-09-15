<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Editor;

final readonly class EditorAssessment
{
    /** @param list<string> $violations */
    public function __construct(
        public EditorTextMetrics $metrics,
        public EditorLimits $limits,
        public array $violations,
    ) {
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    public static function assess(string $source, EditorLimits $limits): self
    {
        $metrics = EditorTextMetrics::measure($source);
        return new self($metrics, $limits, $limits->violations($metrics));
    }
}
