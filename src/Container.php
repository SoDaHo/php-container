<?php

declare(strict_types=1);

namespace Sodaho\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;
use Sodaho\Container\Traits\HasHooks;

/**
 * Lightweight PSR-11 container with autowiring.
 *
 * Hooks:
 * - 'resolve': Triggered when a new entry is created. Data: ['id' => string, 'instance' => mixed]
 * - 'error': Triggered when get() fails. Data: ['id' => string, 'exception' => Throwable]
 */
class Container implements ContainerInterface
{
    use HasHooks;

    /** @var array<string, callable> */
    private array $definitions = [];

    /** @var array<string, string> Interface -> Implementation mappings */
    private array $aliases = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> Entries currently being created (for circular dependency detection) */
    private array $resolving = [];

    /** @var array<string, true> Bindings get() is following to an entry that is being created */
    private array $following = [];

    private bool $reportingError = false;

    /**
     * Create a new Container instance. The environment is not read.
     *
     * @param array{} $config There are no options; the parameter only rejects the config array 1.x took
     *
     * @throws ContainerException If an option is passed: it would have no effect
     */
    public function __construct(array $config = [])
    {
        // @phpstan-ignore notIdentical.alwaysFalse (for callers without static analysis)
        if ($config !== []) {
            throw new ContainerException(
                'The container has no options: the cache and the debug option were removed in 2.0.'
            );
        }
    }

    /**
     * Factory method for fluent creation.
     *
     * @param array{} $config There are no options
     *
     * @throws ContainerException If an option is passed
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Register a service factory.
     *
     * @param string $id The class name or identifier
     * @param callable $factory A closure that returns the instance: fn(Container $c) => new Service(...)
     *
     * @throws ContainerException If the entry has been created or is being created
     */
    public function set(string $id, callable $factory): void
    {
        $this->assertNotCreated($id);
        // The last registration for an id wins
        unset($this->aliases[$id]);
        $this->definitions[$id] = $factory;
    }

    /**
     * Bind an interface to a concrete implementation.
     *
     * Uses a lightweight string mapping instead of closures for better memory efficiency.
     *
     * @param string $interface The interface or abstract class name
     * @param class-string $implementation The concrete class name
     *
     * @throws ContainerException If the entry has been created or is being created
     */
    public function bind(string $interface, string $implementation): self
    {
        $this->assertNotCreated($interface);
        $this->aliases[$interface] = $implementation;
        // Released last: a factory object may have a destructor, which finds the binding in place
        unset($this->definitions[$interface]);
        return $this;
    }

    /**
     * Entries are singletons: a definition for one that exists, or is on its way, would never be used.
     */
    private function assertNotCreated(string $id): void
    {
        if (array_key_exists($id, $this->instances) || isset($this->resolving[$id]) || isset($this->following[$id])) {
            throw new ContainerException("Cannot redefine '$id': the entry has been created or is being created.");
        }
    }

    /**
     * Find an entry of the container by its identifier and returns it.
     *
     * @template T of object
     *
     * @param class-string<T>|string $id Identifier of the entry to look for.
     *
     * @throws NotFoundException No entry was found for this identifier.
     * @throws ContainerException Error while retrieving the entry.
     *
     * @return ($id is class-string<T> ? T : mixed) Entry.
     */
    public function get(string $id): mixed
    {
        // Follow bind() mappings to the entry that is created. Only that entry is guarded against
        // re-entry: a hook may ask for an interface while its implementation is being announced.
        $aliases = [];
        $target = $id;

        while (true) {
            // 1. Singleton: Return existing instance
            if (array_key_exists($target, $this->instances)) {
                $instance = $this->instances[$target];
                break;
            }

            // 2. Manual Definition or 4. Autowiring
            if (!isset($this->aliases[$target]) || $this->aliases[$target] === $target) {
                $outer = $this->following;
                $this->following += $aliases;
                try {
                    $instance = $this->make($target, array_keys($aliases));
                } finally {
                    $this->following = $outer;
                }
                break;
            }

            // 3. Alias: Resolve to implementation (bind() mappings)
            if (isset($aliases[$target])) {
                throw $this->fail($id, $this->circular([...array_keys($aliases), $target]));
            }
            $aliases[$target] = true;
            $target = $this->aliases[$target];
        }

        foreach ($aliases as $alias => $_) {
            $this->instances[$alias] = $instance;
        }

        return $instance;
    }

    /**
     * Create the entry for an id that is not an alias: run its factory or autowire it.
     *
     * @param list<string> $via Aliases that led here (for the circular dependency message)
     */
    private function make(string $id, array $via): mixed
    {
        if (isset($this->resolving[$id])) {
            throw $this->fail($id, $this->circular([...$via, $id]));
        }

        $this->resolving[$id] = true;
        try {
            $instance = isset($this->definitions[$id])
                ? $this->runFactory($id, $this->definitions[$id])
                : $this->resolve($id);
        } finally {
            unset($this->resolving[$id]);
        }

        $this->instances[$id] = $instance;
        $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
        return $instance;
    }

    /**
     * @param list<string> $tail Ids after the entries that are already being created
     */
    private function circular(array $tail): ContainerException
    {
        return new ContainerException(
            'Circular dependency detected: ' . implode(' -> ', [...array_keys($this->resolving), ...$tail])
        );
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool
    {
        $seen = [];
        while (!isset($this->definitions[$id])) {
            if (!isset($this->aliases[$id]) || $this->aliases[$id] === $id) {
                return class_exists($id) && new ReflectionClass($id)->isInstantiable();
            }
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return true;
    }

    /**
     * Report a failure to the 'error' hook; returns the exception for the caller to throw.
     *
     * @template E of ContainerException
     *
     * @param E $exception
     *
     * @return E
     */
    private function fail(string $id, ContainerException $exception): ContainerException
    {
        $this->report($id, $exception);
        return $exception;
    }

    /**
     * Fire the 'error' hook. A hook that uses the container and fails there is not called
     * again for that failure: it would call itself until the stack is exhausted.
     */
    private function report(string $id, \Throwable $exception): void
    {
        if ($this->reportingError) {
            return;
        }

        $this->reportingError = true;
        try {
            $this->trigger('error', ['id' => $id, 'exception' => $exception]);
        } finally {
            $this->reportingError = false;
        }
    }

    /**
     * What a wrapped exception and the ones behind it say, and where they come from. For the debug
     * message only: their text can contain values (a DSN, a path) that getMessage() must not carry.
     */
    private static function describe(\Throwable $e): string
    {
        $chain = [];
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $chain[] = sprintf('%s in %s:%d: %s', $cause::class, $cause->getFile(), $cause->getLine(), $cause->getMessage());
        }

        return implode(' <- ', $chain);
    }

    private function runFactory(string $id, callable $factory): mixed
    {
        try {
            return $factory($this);
        } catch (\Throwable $e) {
            $this->report($id, $e);
            throw new ContainerException(
                "Error while creating service '$id'.",
                0,
                $e,
                self::describe($e)
            );
        }
    }

    private function resolve(string $id): object
    {
        if (!class_exists($id)) {
            throw $this->fail($id, new NotFoundException("Class or service '$id' not found."));
        }

        $reflector = new ReflectionClass($id);

        if (!$reflector->isInstantiable()) {
            throw $this->fail($id, new ContainerException("Class '$id' is not instantiable (abstract or interface)."));
        }

        $parameters = $reflector->getConstructor()?->getParameters() ?? [];

        // Every parameter is checked before the first dependency is created
        $dependencies = [];
        foreach ($parameters as $param) {
            $dependencies[] = $this->dependency($id, $param);
        }

        // Defaults are evaluated in parameter order and only if they are used: a default can be
        // an object (new in initializer) whose constructor relies on what was created before it.
        $arguments = [];
        foreach ($parameters as $index => $param) {
            $depId = $dependencies[$index];

            // No class dependency, or an optional one the container cannot provide: the default
            if ($depId === null || ($param->isOptional() && !$this->has($depId))) {
                $arguments[] = $this->defaultValue($id, $param);
                continue;
            }

            try {
                $arguments[] = $this->get($depId);
            } catch (NotFoundException $e) {
                throw new ContainerException(
                    "Cannot resolve dependency '{$depId}' for parameter '{$param->getName()}' in class '$id'.",
                    0,
                    $e
                );
            }
        }

        try {
            return new $id(...$arguments);
        } catch (\Throwable $e) {
            throw $this->instantiationFailed($id, $e);
        }
    }

    /**
     * The class a constructor parameter asks for, or null if the parameter gets its default.
     */
    private function dependency(string $id, ReflectionParameter $param): ?string
    {
        // Variadic parameters (...$args) are not supported for autowiring
        if ($param->isVariadic()) {
            throw $this->fail($id, new ContainerException(
                "Cannot resolve variadic parameter '...{$param->getName()}' in class '$id'. Use set() to define this service manually."
            ));
        }

        $type = $param->getType();

        // No type hint, Union Types or Intersection Types (not supported for simplicity)
        if (!$type instanceof ReflectionNamedType) {
            if (!$param->isDefaultValueAvailable()) {
                throw $this->fail($id, new ContainerException(
                    "Cannot resolve parameter '{$param->getName()}' in class '$id'. No type hint, union type, or intersection type. Use set() to define this service manually."
                ));
            }
            return null;
        }

        // Primitives (int, string, bool) cannot be autowired unless default value exists
        if ($type->isBuiltin()) {
            if (!$param->isDefaultValueAvailable()) {
                throw $this->fail($id, new ContainerException(
                    "Cannot resolve primitive parameter '{$param->getName()}' (type: {$type->getName()}) in class '$id'. Use set() to define this service manually."
                ));
            }
            return null;
        }

        // It's a class/interface dependency
        return $type->getName();
    }

    /**
     * Evaluate the default of a constructor parameter. It can run code, so it can fail like a constructor.
     */
    private function defaultValue(string $id, ReflectionParameter $param): mixed
    {
        try {
            return $param->getDefaultValue();
        } catch (\Throwable $e) {
            throw $this->instantiationFailed($id, $e);
        }
    }

    private function instantiationFailed(string $id, \Throwable $e): ContainerException
    {
        $this->report($id, $e);

        return new ContainerException(
            "Failed to instantiate '$id'.",
            0,
            $e,
            self::describe($e)
        );
    }
}
