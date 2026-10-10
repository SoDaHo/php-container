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
 * Invariants:
 * - An entry is kept once it is created (a singleton). Only an entry whose resolve hook throws is undone, with every
 *   entry created while that hook ran; the next get() creates them anew, so their constructors run again.
 * - Nothing can be registered while get() runs; an entry that exists cannot be redefined, nor can a type an entry
 *   got a default for: set() and bind() throw instead of registering something that would never be used.
 * - Every id on a chain of bindings becomes an entry of its own when get() follows the chain.
 * - What get() returns for the name of a class or interface is an instance of it; anything else throws.
 * - has() is true for the ids get() has something to create for, and for an id on a cycle of bindings (known, but
 *   broken: get() throws a ContainerException); when has() is false, get() throws a NotFoundException.
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

    /** How many get() calls are running: while one is, set() and bind() throw */
    private int $depth = 0;

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
        // @phpstan-ignore new.static (a subclass whose constructor takes other arguments overrides create() or is created with new)
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
     * @throws ContainerException If get() is running, the entry has been created, or an entry got a default for this type
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
     * Uses a lightweight string mapping instead of closures for better memory efficiency. Both names are loaded (the
     * autoloader runs here). Where a class exists, it must be named as declared, and where both exist, the
     * implementation must implement or extend the interface. A name that does not exist is taken as written and
     * fails at get().
     *
     * @param string $interface The interface or abstract class name
     * @param class-string $implementation The concrete class name
     *
     * @throws ContainerException If get() is running, the entry has been created, an entry got a default for this
     *                            type, a class is named otherwise than declared, or the implementation is no
     *                            subtype of the interface
     */
    public function bind(string $interface, string $implementation): self
    {
        $this->assertNotCreated($interface);
        self::assertBindable($interface, $implementation);
        $this->aliases[$interface] = $implementation;
        // Released last: a factory object may have a destructor, which finds the binding in place
        unset($this->definitions[$interface]);
        return $this;
    }

    /**
     * Check a binding as far as its classes exist. Both must be written as declared, never renamed: ids are looked
     * up as written, so a binding under another spelling (or the name of a class_alias()) would be found by
     * autowiring but not by get() with that spelling, and an implementation renamed to its declared name would pass
     * over a factory registered under the name that was given.
     */
    private static function assertBindable(string $interface, string $implementation): void
    {
        $declared = self::declared($interface);
        if ($declared !== null && $declared !== $interface) {
            throw new ContainerException('Cannot bind \'' . self::name($interface) . "': name it as declared, '" . self::name($declared) . "'.");
        }

        $declaredImplementation = self::declared($implementation);
        if ($declaredImplementation === null) {
            return;
        }
        if ($declaredImplementation !== $implementation) {
            throw new ContainerException(
                'Cannot bind \'' . self::name($interface) . "' to '" . self::name($implementation) . "': name it as declared, '"
                . self::name($declaredImplementation) . "'."
            );
        }

        if ($declared !== null && !is_a($implementation, $declared, true)) {
            throw new ContainerException(
                'Cannot bind \'' . self::name($declared) . "' to '" . self::name($implementation) . "': it neither implements nor extends '"
                . self::name($declared) . "'."
            );
        }
    }

    /**
     * The name a class, interface or enum is declared under, or null if there is none of that name (the autoloader
     * runs for it).
     */
    private static function declared(string $name): ?string
    {
        return class_exists($name) || interface_exists($name) ? new ReflectionClass($name)->name : null;
    }

    /**
     * Entries are singletons: a definition for one that exists would never be used. While get() runs, a definition
     * could change what the entries on their way get: some would see it, others not.
     */
    private function assertNotCreated(string $id): void
    {
        if ($this->depth > 0) {
            throw new ContainerException(
                'Cannot define \'' . self::name($id) . "' while get() is running: register definitions before it, not from a factory or a hook."
            );
        }

        if (array_key_exists($id, $this->instances)) {
            throw new ContainerException('Cannot redefine \'' . self::name($id) . "': the entry has been created.");
        }

        // An entry that got a default for this type would keep it: the definition would reach it as little
        if (isset($this->defaulted[$id])) {
            // Never empty: forget() removes a type together with its last entry
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
        $this->depth++;
        try {
            return $this->entry($id);
        } finally {
            $this->depth--;
        }
    }

    /**
     * What get() returns: the entry for an id, created if need be.
     */
    private function entry(string $id): mixed
    {
        // Follow bind() mappings to the entry that is created. Only that entry is guarded against
        // re-entry: a hook may ask for an interface while its implementation is being announced.
        $aliases = [];
        $target = $id;
        $cached = false;

        while (true) {
            // An entry that exists: also reached through a binding that was followed before
            if (array_key_exists($target, $this->instances)) {
                $instance = $this->instances[$target];
                $cached = true;
                break;
            }

            // The end of the chain: its factory runs or the class is autowired (a class bound to itself is that, too)
            if (!isset($this->aliases[$target]) || $this->aliases[$target] === $target) {
                $instance = $this->make($target, array_keys($aliases));
                break;
            }

            // A binding: follow it, unless it was followed before on this chain (a cycle)
            if (isset($aliases[$target])) {
                throw $this->fail($id, $this->circular([...array_keys($aliases), $target]));
            }
            $aliases[$target] = true;
            $target = $this->aliases[$target];
        }

        // What get() of a class returns is an instance of it. A new entry was checked when it was made; one created
        // before its class existed (a factory under a name that was no class yet) is checked when it is found.
        if ($cached && (class_exists($target) || interface_exists($target)) && !$instance instanceof $target) {
            throw $this->fail($target, new ContainerException(
                'Cannot resolve \'' . self::name($target) . "': its entry is " . get_debug_type($instance) . ', not an instance of it.'
            ));
        }

        // bind() checked what existed when it ran; a binding to an id without a class (a factory under a name) or to
        // a class loaded later is checked here.
        foreach (array_keys($aliases) as $alias) {
            $alias = (string) $alias;
            if ((class_exists($alias) || interface_exists($alias)) && !$instance instanceof $alias) {
                throw $this->fail($alias, new ContainerException(
                    'Cannot resolve \'' . self::name($alias) . "': it is bound to '" . self::name($this->aliases[$alias])
                    . "', whose entry is " . get_debug_type($instance) . ', not an instance of it.'
                ));
            }
        }

        // Every binding on the way is an entry from now on, so it can no longer be redefined either
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
                : $this->autowire($id);
        } catch (\Throwable $e) {
            // Nothing was created: the defaults autowire() marked for the entry are not in use
            $this->forget([$id]);
            throw $e;
        } finally {
            unset($this->resolving[$id]);
        }

        // Stored before the hook runs, so that a hook asking for the entry (or an interface bound to it) gets
        // this instance instead of a circular dependency. Entries are only ever appended, so the entries a hook
        // creates come after the mark: a hook that throws undoes the entry and all of them.
        $mark = count($this->instances);
        $this->instances[$id] = $instance;
        try {
            $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
        } catch (\Throwable $e) {
            // The local reference goes first, so that the rollback can release the entry (unless the hook's
            // exception still holds it in the arguments of its trace)
            unset($instance);
            $released = $this->rollback($mark);
            // What a hook throws leaves get() as a ContainerException, as PSR-11 asks: the message names the entry
            // only (the hook's text may carry values), the hook's exception is in getPrevious() and the debug message.
            // A NotFoundExceptionInterface would also tell the caller that an entry has() is true for does not exist.
            throw new ContainerException(
                'Resolve hook failed for \'' . self::name($id) . "'.",
                0,
                $e,
                self::describe($e) . ($released === null ? '' : '; ' . $released)
            );
        }

        return $instance;
    }

    /**
     * Undo an entry whose resolve hook threw, with every entry created after it while the hook ran: the entry never
     * passed the hook, and an entry the hook created may hold it (a class that needs it, a binding to it, a factory
     * that asked for it). Keeping those would leave two instances once the next get() creates the entry anew. Entries
     * created before it, its dependencies among them, passed their own hooks and stay. Returns what destructors threw.
     */
    private function rollback(int $mark): ?string
    {
        $undone = array_slice($this->instances, $mark, null, true);
        $this->instances = array_slice($this->instances, 0, $mark, true);
        $this->forget(array_keys($undone));

        return self::release($undone);
    }

    /**
     * Drop values the container gives up on (entries a failed hook undid, a factory result of the wrong type) and run
     * the destructors of those nothing else holds here, where what they throw is caught: it must not replace the
     * exception get() throws for the failure. Returns a description of it for that exception's debug message.
     *
     * @param array<mixed> $values Held by the caller nowhere else, so that unsetting an element can destroy it
     */
    private static function release(array &$values): ?string
    {
        $thrown = [];
        foreach (array_keys($values) as $key) {
            try {
                self::destroy($values, $key);
            } catch (\Throwable $e) {
                $thrown[] = self::describe($e);
            }
        }

        return $thrown === [] ? null : 'A destructor threw while the container discarded it: ' . implode('; ', $thrown);
    }

    /**
     * Unset one value. If nothing else holds it, its destructor runs now, and what it throws comes out of here.
     *
     * @param array<mixed> $values
     *
     * @throws \Throwable What the destructor throws
     */
    // @phpstan-ignore throws.unusedType (unset() runs the destructor, whose exception PHPStan does not follow)
    private static function destroy(array &$values, int|string $key): void
    {
        unset($values[$key]);
    }

    /**
     * Drop the defaults the given entries were given: the entries are gone, so a type is open again for a definition
     * unless another entry got the default as well.
     *
     * @param list<int|string> $entries
     */
    private function forget(array $entries): void
    {
        $gone = array_flip($entries);
        foreach ($this->defaulted as $type => $users) {
            $users = array_diff_key($users, $gone);
            if ($users === []) {
                unset($this->defaulted[$type]);
            } else {
                $this->defaulted[$type] = $users;
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
     * Returns true if the container can return an entry for the given identifier. An id on a cycle of bindings counts
     * as known: get() throws a ContainerException for it, and PSR-11 allows that only when has() is true.
     */
    public function has(string $id): bool
    {
        $target = $this->target($id);

        return $target === null
            || isset($this->definitions[$target])
            || (class_exists($target) && new ReflectionClass($target)->isInstantiable());
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
     * Report a failure to the 'error' hook and return the exception for the caller to throw: $exception, or, if the
     * hook threw, one of the same class and message with $exception as previous and what the hook threw in the debug
     * message. A failing hook (a log sink that is down) must not hide the failure, nor turn a NotFoundException into
     * something else or the other way round.
     *
     * @param \Throwable|null $cause What the hook receives, if not $exception: what a factory or constructor threw
     */
    private function fail(string $id, ContainerException $exception, ?\Throwable $cause = null): ContainerException
    {
        $thrown = $this->report($id, $cause ?? $exception);
        if ($thrown === null) {
            return $exception;
        }

        $debug = 'The error hook threw ' . self::describe($thrown);

        return $exception instanceof NotFoundException
            ? new NotFoundException($exception->getMessage(), 0, $exception, $debug)
            : new ContainerException($exception->getMessage(), 0, $exception, $debug);
    }

    /**
     * Fire the 'error' hook and return what it threw, if it did. A hook that uses the container and fails there is
     * not called again for that failure: it would call itself until the stack is exhausted.
     */
    private function report(string $id, \Throwable $exception): ?\Throwable
    {
        if ($this->reportingError) {
            return null;
        }

        $this->reportingError = true;
        try {
            $this->trigger('error', ['id' => $id, 'exception' => $exception]);

            return null;
        } catch (\Throwable $thrown) {
            return $thrown;
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

    /**
     * Run a set() factory. Whatever it throws is wrapped, so that get() throws a ContainerException as PSR-11
     * asks; the message names the entry only, the text of the cause goes to the debug message. A factory
     * registered under a class name must return an instance of it: get() of a class promises one.
     */
    private function runFactory(string $id, callable $factory): mixed
    {
        try {
            $instance = $factory($this);
        } catch (\Throwable $e) {
            throw $this->fail($id, new ContainerException(
                'Error while creating service \'' . self::name($id) . "'.",
                0,
                $e,
                self::describe($e)
            ), $e);
        }

        if ((class_exists($id) || interface_exists($id)) && !$instance instanceof $id) {
            $type = get_debug_type($instance);
            $discarded = [$instance];
            unset($instance);
            $released = self::release($discarded);
            throw $this->fail($id, new ContainerException(
                'Error while creating service \'' . self::name($id) . "': the factory returned $type, not an instance of it.",
                0,
                null,
                $released
            ));
        }

        return $instance;
    }

    /**
     * Autowire a class: every constructor parameter is checked first, then the dependencies are created in
     * parameter order and the class is instantiated. Nothing is created when a parameter is of a kind the container
     * cannot fill (no type, a primitive, a variadic, another union). A dependency that fails after others were
     * created leaves those as entries: they passed their own hooks.
     *
     * A type a parameter gets the default for is marked for the entry at once (make() drops the mark if the entry
     * is not created): a definition for that type would never reach the entry.
     */
    private function autowire(string $id): object
    {
        if (!class_exists($id)) {
            // An interface gets here when nothing is bound to it, or itself
            $message = match (true) {
                !interface_exists($id) => 'Class or service \'' . self::name($id) . "' not found.",
                ($this->aliases[$id] ?? null) === $id => 'Interface \'' . self::name($id) . "' is bound to itself: bind it to a class that implements it.",
                default => 'Interface \'' . self::name($id) . "' not found: no implementation is bound to it.",
            };
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

        // A container created by autowiring is a new, empty one: whatever asks for it would bypass every factory,
        // binding and hook of this one without notice
        if ($reflector->implementsInterface(ContainerInterface::class)) {
            throw $this->fail($id, new ContainerException(
                'Cannot autowire \'' . self::name($id) . "': it is a container, and autowiring would create a new, empty one. "
                . 'Register the container for it: set(\\' . self::name($id) . '::class, fn (Container $c) => $c).'
            ));
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
                // An optional container would be null although a container is right there: as silent as a new one
                if ($depId !== null && is_a($depId, ContainerInterface::class, true)) {
                    throw $this->fail($depId, $this->containerNotRegistered($id, $param, $depId));
                }
                $arguments[] = $this->defaultValue($id, $param);
                if ($depId !== null) {
                    $this->defaulted[$depId][$id] = true;
                }
                continue;
            }

            // A dependency has() is false for is "not found" for whoever asks for it, but an error of the class
            // that needs it: has() is true for that class, so its get() must not throw a NotFoundException. Only
            // such a dependency makes get() throw one; what hooks throw is wrapped where they run.
            try {
                $arguments[] = $this->get($depId);
            } catch (NotFoundException $e) {
                if (is_a($depId, ContainerInterface::class, true)) {
                    throw $this->containerNotRegistered($id, $param, $depId, $e);
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
     * A parameter asks for a container interface nothing is registered for. The container does not register itself:
     * which container a service gets is the application's choice, so the message says how to make it.
     */
    private function containerNotRegistered(string $id, ReflectionParameter $param, string $depId, ?\Throwable $previous = null): ContainerException
    {
        return new ContainerException(
            "Cannot resolve parameter '{$param->getName()}' in class '" . self::name($id) . "': '" . self::name($depId)
            . "' is a container, which is not autowired. Register it: set(\\" . self::name($depId) . '::class, fn (Container $c) => $c).',
            0,
            $previous
        );
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

        // A class with null or false (T|false, T|false|null) is that class, like ?T: otherwise a binding for it
        // would be passed over for the default, and a class that cannot be built would not be noticed
        if ($type instanceof ReflectionUnionType) {
            $type = self::classOf($type) ?? $type;
        }

        // No type hint, other Union Types or Intersection Types (not supported for simplicity)
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

        return self::declared($name) ?? $name;
    }

    /**
     * The one class of a union that has nothing else but null and false, or null for any other union.
     */
    private static function classOf(ReflectionUnionType $union): ?ReflectionNamedType
    {
        $class = null;
        foreach ($union->getTypes() as $type) {
            if (!$type instanceof ReflectionNamedType) {
                return null;
            }
            if (!$type->isBuiltin()) {
                if ($class !== null) {
                    return null;
                }
                $class = $type;
            } elseif (!in_array($type->getName(), ['null', 'false'], true)) {
                return null;
            }
        }

        return $class;
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

    /**
     * Wrap what a constructor or a default value threw, and report it to the error hook where it happened:
     * the hook gets the original, get() throws a ContainerException that names the class only.
     */
    private function instantiationFailed(string $id, \Throwable $e): ContainerException
    {
        return $this->fail($id, new ContainerException(
            'Failed to instantiate \'' . self::name($id) . "'.",
            0,
            $e,
            self::describe($e)
        ), $e);
    }
}
