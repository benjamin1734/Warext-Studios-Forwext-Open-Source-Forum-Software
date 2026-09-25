<?php

declare(strict_types=1);

namespace Forwext\Core\Api\V1;

use Forwext\Core\Http\HttpMethod;
use InvalidArgumentException;

final readonly class ApiV1EndpointDefinition
{
    /** @var non-empty-list<HttpMethod> */
    public array $methods;
    /** @var array<string,string> */
    public array $requirements;

    /**
     * @param non-empty-list<HttpMethod> $methods
     * @param array<string,string> $requirements
     */
    public function __construct(
        public string $routeName,
        public string $path,
        array $methods,
        public ApiV1Operation $operation,
        public ?ApiV1Resource $resource = null,
        public ?ApiV1Scope $scope = null,
        public bool $public = true,
        array $requirements = [],
    ) {
        if (preg_match('/^api\.v1\.[a-z0-9._-]+$/D', $this->routeName) !== 1) {
            throw new InvalidArgumentException('API v1 route name is invalid.');
        }
        if ($this->path !== '/api/v1' && !str_starts_with($this->path, '/api/v1/')) {
            throw new InvalidArgumentException('API v1 endpoint path is outside /api/v1.');
        }
        if ($methods === []) {
            throw new InvalidArgumentException('API v1 endpoint requires an HTTP method.');
        }
        foreach ($methods as $method) {
            if (!$method instanceof HttpMethod) {
                throw new InvalidArgumentException('API v1 endpoint contains an invalid HTTP method.');
            }
        }
        if (($this->resource === null) !== ($this->scope === null)) {
            throw new InvalidArgumentException('API v1 endpoint resource and scope must be declared together.');
        }
        if ($this->resource !== null && $this->scope?->resource() !== $this->resource) {
            throw new InvalidArgumentException('API v1 endpoint scope does not match its resource.');
        }

        $this->methods = array_values($methods);
        $this->requirements = $requirements;
    }
}
