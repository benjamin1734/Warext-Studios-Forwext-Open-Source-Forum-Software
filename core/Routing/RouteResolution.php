<?php

declare(strict_types=1);

namespace Forwext\Core\Routing;

use Forwext\Core\Http\HttpMethod;

final readonly class RouteResolution
{
    /**
     * @param list<HttpMethod> $allowedMethods
     */
    public function __construct(
        public ?RouteMatch $match,
        public array $allowedMethods = [],
    ) {
    }

    public function pathExists(): bool
    {
        return $this->match !== null || $this->allowedMethods !== [];
    }
}
