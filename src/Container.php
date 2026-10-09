<?php

declare(strict_types=1);

namespace Sodaho\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
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
    use HasHooks {
        on as private addHook;
    }

    /** @var list<string> Events on() accepts; a subclass that fires its own adds them here */
    protected const array EVENTS = ['resolve', 'error'];

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

    /** @var array<string, array<string, true>> Types a created entry got a default for instead: type -> those entries */
    private array $defaulted = [];

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
    public static function create(array $config = []): static
    {
        // @phpstan-ignore new.static (a subclass whose constructor takes other arguments does not use create())
        return new static($config);
    }

    /**
     * Register a hook callback for an event.
     *
     * @param string $event 'resolve' or 'error'
     * @param callable $callback Callback receiving event data array
     *
     * @throws ContainerException If the event does not exist: the callback would never run
     */
    public function on(string $event, callable $callback): static
    {
        if (!in_array($event, static::EVENTS, true)) {
            throw new ContainerException(
                'Unknown event \'' . self::name($event) . "'. Available: " . implode(', ', static::EVENTS) . '.'
            );
        }

        return $this->addHook($event, $callback);
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
            throw new ContainerException('Cannot redefine \'' . self::name($id) . "': the entry has been created or is being created.");
        }

        // An entry that got a default for this type would keep it: the definition would reach it as little
        if (isset($this->defaulted[$id])) {
            // Never empty: discard() removes a type together with its last entry
            $entry = (string) array_key_first($this->defaulted[$id]);
            throw new ContainerException(
                'Cannot define \'' . self::name($id) . "': '" . self::name($entry) . "' has been created with the default value in its place."
            );
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
        $defaulted = [];
        try {
            $instance = isset($this->definitions[$id])
                ? $this->runFactory($id, $this->definitions[$id])
                : $this->resolve($id, $defaulted);
        } finally {
            unset($this->resolving[$id]);
        }

        // The defaults the entry got count from the moment the entry exists, not before: a get() that
        // failed has used none, and the types stay open for a definition.
        $this->instances[$id] = $instance;
        foreach ($defaulted as $type) {
            $this->defaulted[$type][$id] = true;
        }

        // Stored before the hook runs, so that a hook asking for the entry (or an interface bound to it) gets
        // this instance instead of a circular dependency. A hook that throws undoes the entry.
        try {
            $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
        } catch (\Throwable $e) {
            $this->discard($id, $defaulted);
            throw $e;
        }

        return $instance;
    }

    /**
     * Undo an entry whose resolve hook threw: it never passed the hook, the next get() creates it anew.
     * Bindings to it that the hook asked for go as well. They are found by key, not by value: another
     * entry may hold the same value (two factories returning 1). The defaults it got are no longer in use.
     *
     * @param list<string> $defaulted
     */
    private function discard(string $id, array $defaulted): void
    {
        unset($this->instances[$id]);
        foreach (array_keys($this->instances) as $key) {
            $key = (string) $key;
            if (isset($this->aliases[$key]) && $this->target($key) === $id) {
                unset($this->instances[$key]);
            }
        }

        foreach ($defaulted as $type) {
            unset($this->defaulted[$type][$id]);
            if ($this->defaulted[$type] === []) {
                unset($this->defaulted[$type]);
            }
        }
    }

    /**
     * @param list<string> $tail Ids after the entries that are already being created
     */
    private function circular(array $tail): ContainerException
    {
        return new ContainerException(
            'Circular dependency detected: ' . implode(' -> ', array_map(
                fn (int|string $id) => self::name((string) $id),
                [...array_keys($this->resolving), ...$tail]
            ))
        );
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool
    {
        $target = $this->target($id);

        return $target !== null
            && (isset($this->definitions[$target]) || (class_exists($target) && new ReflectionClass($target)->isInstantiable()));
    }

    /**
     * The id a chain of bindings ends at: the one with the factory, or the class that is autowired.
     * Null if the bindings form a cycle.
     */
    private function target(string $id): ?string
    {
        $seen = [];
        while (isset($this->aliases[$id]) && $this->aliases[$id] !== $id) {
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $id = $this->aliases[$id];
        }

        return $id;
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
     * An id as exception messages name it: control characters escaped (a line break as \x0A), so that an id
     * with a line break cannot add a line to a log that writes the message. Backslashes of class names stay.
     */
    private static function name(string $id): string
    {
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            fn (array $char) => sprintf('\x%02X', ord($char[0])),
            $id
        );
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
                'Error while creating service \'' . self::name($id) . "'.",
                0,
                $e,
                self::describe($e)
            );
        }
    }

    /**
     * @param list<string> $defaulted Filled with the types of the parameters that got their default
     *
     * @param-out list<string> $defaulted
     */
    private function resolve(string $id, array &$defaulted): object
    {
        if (!class_exists($id)) {
            // An interface gets here when nothing is bound to it
            $message = interface_exists($id)
                ? 'Interface \'' . self::name($id) . "' not found: no implementation is bound to it."
                : 'Class or service \'' . self::name($id) . "' not found.";
            throw $this->fail($id, new NotFoundException($message));
        }

        $reflector = new ReflectionClass($id);

        // has() is false for a class that cannot be instantiated: for PSR-11 that is "not found", too
        if (!$reflector->isInstantiable()) {
            $reason = match (true) {
                $reflector->isEnum() => 'it is an enum',
                $reflector->isAbstract() => 'it is abstract',
                default => 'its constructor is not public',
            };
            throw $this->fail($id, new NotFoundException('Class \'' . self::name($id) . "' is not instantiable: $reason."));
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

            // No class dependency, or an optional one nothing is bound to and the container cannot create: the default
            if ($depId === null || ($param->isOptional() && !isset($this->aliases[$depId]) && !$this->has($depId))) {
                $arguments[] = $this->defaultValue($id, $param);
                if ($depId !== null) {
                    $defaulted[] = $depId;
                }
                continue;
            }

            // A dependency has() is false for is "not found" for whoever asks for it, but an error of the class
            // that needs it: has() is true for that class, so its get() must not throw a NotFoundException.
            // Judged before get() runs: if has() is true for the dependency, a NotFoundException is a hook's.
            $missing = !$this->has($depId);

            try {
                $arguments[] = $this->get($depId);
            } catch (NotFoundException $e) {
                if (!$missing) {
                    throw $e;
                }

                throw new ContainerException(
                    'Cannot resolve dependency \'' . self::name($depId) . "' for parameter '{$param->getName()}' in class '" . self::name($id) . "'.",
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
                "Cannot resolve variadic parameter '...{$param->getName()}' in class '" . self::name($id) . "'. Use set() to define this service manually."
            ));
        }

        $type = $param->getType();

        // No type hint, Union Types or Intersection Types (not supported for simplicity)
        if (!$type instanceof ReflectionNamedType) {
            if (!$param->isDefaultValueAvailable()) {
                $kind = match (true) {
                    $type === null => 'no type',
                    $type instanceof ReflectionUnionType => 'a union type',
                    default => 'an intersection type',
                };
                throw $this->fail($id, new ContainerException(
                    "Cannot resolve parameter '{$param->getName()}' in class '" . self::name($id) . "': it has $kind. Use set() to define this service manually."
                ));
            }
            return null;
        }

        // Primitives (int, string, bool) cannot be autowired unless default value exists
        if ($type->isBuiltin()) {
            if (!$param->isDefaultValueAvailable()) {
                throw $this->fail($id, new ContainerException(
                    "Cannot resolve primitive parameter '{$param->getName()}' (type: {$type->getName()}) in class '" . self::name($id) . "'. Use set() to define this service manually."
                ));
            }
            return null;
        }

        // A class or interface, named as declared: a type may spell it in another case, and only the declared
        // name reaches the entry, binding or factory registered under ::class. A name that does not exist (or
        // self) stays as written; the guard keeps ReflectionClass from throwing for an optional dependency.
        $name = $type->getName();
        if (class_exists($name) || interface_exists($name)) {
            return new ReflectionClass($name)->name;
        }

        return $name;
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
            'Failed to instantiate \'' . self::name($id) . "'.",
            0,
            $e,
            self::describe($e)
        );
    }
}
