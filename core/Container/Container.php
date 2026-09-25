<?php

declare(strict_types=1);

namespace Forwext\Core\Container;

use Closure;
use Forwext\Core\Container\Exception\CircularDependencyException;
use Forwext\Core\Container\Exception\ContainerException;
use Forwext\Core\Container\Exception\OverrideNotAllowedException;
use Forwext\Core\Container\Exception\ServiceNotFoundException;
use Forwext\Core\Container\Exception\UnresolvableDependencyException;
use Forwext\Core\Extension\ExtensionOwner;
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

    /** @var array<string,ExtensionOwner> */
    private array $bindingOwners = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var list<string> */
    private array $resolving = [];

    /** @var array<string,list<ServiceDecorator>> */
    private array $decorators = [];

    private int $decoratorSequence = 0;

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
        $this->bindOwned($id, $concrete, $lifetime, ExtensionOwner::core());
    }

    public function bindExtension(
        string $id,
        ExtensionOwner $owner,
        Closure|string|null $concrete = null,
        ServiceLifetime $lifetime = ServiceLifetime::Transient,
    ): void {
        $this->bindOwned($id, $concrete, $lifetime, $owner);
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
        unset($this->bindings[$id], $this->instances[$id], $this->bindingOwners[$id]);
        $this->bindings[$id] = new Binding($concrete, $lifetime);
        $this->bindingOwners[$id] = ExtensionOwner::core();
    }

    public function overrideInstance(string $id, mixed $instance): void
    {
        $this->assertOverridesAllowed($id);
        unset($this->bindings[$id], $this->instances[$id], $this->bindingOwners[$id]);
        $this->instances[$id] = $instance;
    }

    /**
     * Register one deterministic decorator for a service and extension owner.
     *
     * @param Closure(mixed,self):mixed $decorator
     */
    public function decorate(
        string $id,
        Closure $decorator,
        ExtensionOwner $owner,
        int $priority = 0,
    ): void {
        if ($id === '') {
            throw new ContainerException('Decorated service id cannot be empty.');
        }
        if (array_key_exists($id, $this->instances) || in_array($id, $this->resolving, true)) {
            throw new ContainerException(sprintf(
                'Service "%s" cannot be decorated after or during singleton resolution.',
                $id,
            ));
        }

        foreach ($this->decorators[$id] ?? [] as $registered) {
            if ($registered->owner->equals($owner)) {
                throw new ContainerException(sprintf(
                    'Extension owner "%s" already decorates service "%s".',
                    $owner->value(),
                    $id,
                ));
            }
        }

        $this->decorators[$id] ??= [];
        $this->decorators[$id][] = new ServiceDecorator(
            $owner,
            $decorator,
            $priority,
            $this->decoratorSequence++,
        );
    }

    /** @return list<ContainerServiceDiagnostic> */
    public function extensionDiagnostics(): array
    {
        $ids = array_values(array_unique([
            ...array_keys($this->bindings),
            ...array_keys($this->decorators),
        ]));
        sort($ids, SORT_STRING);

        $result = [];
        foreach ($ids as $id) {
            $binding = $this->bindings[$id] ?? null;
            $decorators = array_map(
                static fn (ServiceDecorator $decorator): ServiceDecoratorDiagnostic => new ServiceDecoratorDiagnostic(
                    $decorator->owner->value(),
                    $decorator->priority,
                ),
                $this->sortedDecorators($id),
            );
            $target = null;
            if ($binding instanceof Binding) {
                $target = $binding->concrete instanceof Closure ? 'factory' : $binding->concrete;
            }

            $result[] = new ContainerServiceDiagnostic(
                $id,
                $target,
                $binding?->lifetime,
                $this->bindingOwners[$id]?->value() ?? null,
                $decorators,
                $this->diagnosticIssues($id),
            );
        }

        return $result;
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

                return $this->applyDecorators($id, $this->autowire($id));
            }

            $resolved = $this->applyDecorators(
                $id,
                $this->resolveConcrete($id, $binding->concrete),
            );

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

    private function applyDecorators(string $id, mixed $service): mixed
    {
        foreach ($this->sortedDecorators($id) as $decorator) {
            $service = ($decorator->factory)($service, $this);
            if ((class_exists($id) || interface_exists($id)) && !$service instanceof $id) {
                throw new ContainerException(sprintf(
                    'Decorator owned by "%s" returned an incompatible value for "%s".',
                    $decorator->owner->value(),
                    $id,
                ));
            }
        }

        return $service;
    }

    /** @return list<ServiceDecorator> */
    private function sortedDecorators(string $id): array
    {
        $decorators = $this->decorators[$id] ?? [];
        usort(
            $decorators,
            static fn (ServiceDecorator $left, ServiceDecorator $right): int =>
                [$right->priority, $left->sequence] <=> [$left->priority, $right->sequence],
        );

        return $decorators;
    }

    /** @return list<string> */
    private function diagnosticIssues(string $id): array
    {
        $issues = [];
        if (($this->decorators[$id] ?? []) !== [] && !isset($this->bindings[$id]) && !$this->has($id)) {
            $issues[] = 'decorator_without_resolvable_base';
        }

        $path = [];
        $seen = [];
        $current = $id;
        while (isset($this->bindings[$current]) && is_string($this->bindings[$current]->concrete)) {
            if (isset($seen[$current])) {
                $path[] = $current;
                $issues[] = 'binding_cycle:' . implode('->', $path);
                break;
            }
            $seen[$current] = true;
            $path[] = $current;
            $current = $this->bindings[$current]->concrete;
        }

        if ($path !== [] && !isset($this->bindings[$current]) && !$this->has($current)) {
            $issues[] = 'unresolved_binding_target:' . $current;
        }

        return $issues;
    }

    private function bindOwned(
        string $id,
        Closure|string|null $concrete,
        ServiceLifetime $lifetime,
        ExtensionOwner $owner,
    ): void {
        $this->assertCanBind($id);
        $this->bindings[$id] = new Binding($concrete ?? $id, $lifetime);
        $this->bindingOwners[$id] = $owner;
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
