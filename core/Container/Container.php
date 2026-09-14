<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

use Closure;
use Forwext\Core\Container\Exception\CircularDependencyException;
use Forwext\Core\Container\Exception\ContainerException;
use Forwext\Core\Container\Exception\OverrideNotAllowedException;
use Forwext\Core\Container\Exception\ServiceNotFoundException;
use Forwext\Core\Container\Exception\UnresolvableDependencyException;
use ReflectionClass;
use ReflectionException;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

final class Container
{
    /** @var array<string, Binding> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var list<string> */
    private array $resolving = [];

    public function __construct(private readonly bool $allowOverrides = false)
    {
    }

    public static function forTesting(): self
    {
        return new self(allowOverrides: true);
    }

    public function bind(
        string $id,
        Closure|string|null $concrete = null,
        ServiceLifetime $lifetime = ServiceLifetime::Transient,
    ): void {
        $this->assertCanBind($id);
        $this->bindings[$id] = new Binding($concrete ?? $id, $lifetime);
    }

    public function singleton(string $id, Closure|string|null $concrete = null): void
    {
        $this->bind($id, $concrete, ServiceLifetime::Singleton);
    }

    public function lazy(
        string $id,
        Closure $factory,
        ServiceLifetime $lifetime = ServiceLifetime::Singleton,
    ): void {
        $this->bind($id, $factory, $lifetime);
    }

    public function instance(string $id, mixed $instance): void
    {
        $this->assertCanBind($id);
        $this->instances[$id] = $instance;
    }

    public function override(
        string $id,
        Closure|string $concrete,
        ServiceLifetime $lifetime = ServiceLifetime::Singleton,
    ): void {
        $this->assertOverridesAllowed($id);
        unset($this->bindings[$id], $this->instances[$id]);
        $this->bindings[$id] = new Binding($concrete, $lifetime);
    }

    public function overrideInstance(string $id, mixed $instance): void
    {
        $this->assertOverridesAllowed($id);
        unset($this->bindings[$id], $this->instances[$id]);
        $this->instances[$id] = $instance;
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->instances) || isset($this->bindings[$id])) {
            return true;
        }

        if (!class_exists($id)) {
            return false;
        }

        try {
            return (new ReflectionClass($id))->isInstantiable();
        } catch (ReflectionException) {
            return false;
        }
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (in_array($id, $this->resolving, true)) {
            throw CircularDependencyException::fromPath([...$this->resolving, $id]);
        }

        $this->resolving[] = $id;

        try {
            $binding = $this->bindings[$id] ?? null;

            if ($binding === null) {
                if (!$this->has($id)) {
                    throw ServiceNotFoundException::forId($id);
                }

                return $this->autowire($id);
            }

            $resolved = $this->resolveConcrete($id, $binding->concrete);

            if ($binding->lifetime === ServiceLifetime::Singleton) {
                $this->instances[$id] = $resolved;
            }

            return $resolved;
        } finally {
            array_pop($this->resolving);
        }
    }

    public function make(string $class): object
    {
        return $this->autowire($class);
    }

    private function assertCanBind(string $id): void
    {
        if (isset($this->bindings[$id]) || array_key_exists($id, $this->instances)) {
            throw new ContainerException(sprintf(
                'Service "%s" is already bound. Explicit overrides are allowed only in a testing container.',
                $id,
            ));
        }
    }

    private function assertOverridesAllowed(string $id): void
    {
        if (!$this->allowOverrides) {
            throw OverrideNotAllowedException::forId($id);
        }
    }

    private function resolveConcrete(string $id, Closure|string $concrete): mixed
    {
        if ($concrete instanceof Closure) {
            return $concrete($this);
        }

        if (class_exists($concrete)) {
            return $this->autowire($concrete);
        }

        if ($concrete !== $id && (isset($this->bindings[$concrete]) || array_key_exists($concrete, $this->instances))) {
            return $this->get($concrete);
        }

        throw ServiceNotFoundException::forId($concrete);
    }

    private function autowire(string $class): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $exception) {
            throw new ServiceNotFoundException(
                sprintf('Cannot reflect service "%s".', $class),
                previous: $exception,
            );
        }

        if (!$reflection->isInstantiable()) {
            throw new ServiceNotFoundException(sprintf('Service "%s" is not instantiable.', $class));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($class, $parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function resolveParameter(string $class, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->get($this->normalizeClassType($class, $type));
        }

        if ($type instanceof ReflectionUnionType) {
            /** @var list<ReflectionNamedType> $classTypes */
            $classTypes = [];

            foreach ($type->getTypes() as $candidate) {
                if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin()) {
                    $classTypes[] = $candidate;
                }
            }

            if (count($classTypes) === 1) {
                return $this->get($this->normalizeClassType($class, $classTypes[0]));
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        if ($type instanceof ReflectionIntersectionType) {
            throw UnresolvableDependencyException::forParameter($class, $parameter);
        }

        throw UnresolvableDependencyException::forParameter($class, $parameter);
    }

    private function normalizeClassType(string $class, ReflectionNamedType $type): string
    {
        return match ($type->getName()) {
            'self', 'static' => $class,
            'parent' => get_parent_class($class) ?: throw new ContainerException(sprintf(
                'Cannot resolve parent type for "%s" because the class has no parent.',
                $class,
            )),
            default => $type->getName(),
        };
    }
}
