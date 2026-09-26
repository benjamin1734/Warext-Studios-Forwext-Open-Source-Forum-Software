<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

final readonly class UpdateFileSnapshot
{
    /**
     * @param list<string> $added
     * @param array<string,string> $backups
     */
    public function __construct(
        public string $id,
        public string $directory,
        public array $added,
        public array $backups,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->id) !== 1) {
            throw new UpdateException('Update file snapshot id is invalid.');
        }
        if ($this->directory === '' || str_contains($this->directory, "\0")) {
            throw new UpdateException('Update file snapshot directory is invalid.');
        }
    }
}
