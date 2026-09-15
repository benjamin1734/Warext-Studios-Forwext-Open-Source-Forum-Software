<?php

declare(strict_types=1);

namespace Forwext\Core\Forum\Attachment;

use InvalidArgumentException;

final readonly class AttachmentFilename
{
    private function __construct(private string $value) {}

    public static function fromClient(string $value): self
    {
        $value = trim(str_replace('\\', '/', $value));
        $value = basename($value);
        if ($value === '' || strlen($value) > 180 || str_contains($value, "\0")
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || preg_match('//u', $value) !== 1
        ) {
            throw new InvalidArgumentException('Attachment filename is invalid.');
        }
        return new self($value);
    }

    public function value(): string { return $this->value; }
}
