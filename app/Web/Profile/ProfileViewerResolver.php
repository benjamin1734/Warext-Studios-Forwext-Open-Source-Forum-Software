<?php

declare(strict_types=1);

namespace Forwext\App\Web\Profile;

use Forwext\Core\Domain\Entity\EntityId;
use Forwext\Core\Http\Request;

interface ProfileViewerResolver
{
    public function resolve(Request $request): ?EntityId;
}
