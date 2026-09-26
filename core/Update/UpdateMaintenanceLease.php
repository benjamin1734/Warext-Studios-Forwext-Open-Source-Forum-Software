<?php

declare(strict_types=1);

namespace Forwext\Core\Update;

use Forwext\Core\Migration\SemanticVersion;

final readonly class UpdateMaintenanceLease
{
    public function __construct(
        public string $token,
        public SemanticVersion $sourceVersion,
        public SemanticVersion $targetVersion,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $this->token) !== 1) {
            throw new UpdateException('Update maintenance token is invalid.');
        }
    }
}
