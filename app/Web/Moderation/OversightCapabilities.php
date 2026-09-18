<?php

declare(strict_types=1);

namespace Forwext\App\Web\Moderation;

final readonly class OversightCapabilities
{
    public function __construct(public bool $canReview)
    {
    }
}
