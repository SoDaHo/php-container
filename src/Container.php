<?php

declare(strict_types=1);

namespace Sodaho\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Sodaho\Container\Cache\ContainerCache;
use Sodaho\Container\Exception\CacheException;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;
use Sodaho\Container\Traits\HasHooks;

/**
 * Lightweight PSR-11 container with autowiring and optional caching.
 *
 * Hooks:
 * - 'resolve': Triggered when a new entry is created. Data: ['id' => string, 'instance' => mixed]
 * - 'error': Triggered when get() fails. Data: ['id' => string, 'exception' => Throwable]
 * - 'cacheHit': Triggered when class metadata is found in cache. Data: ['id' => string]
 * - 'cacheMiss': Triggered when class metadata is not in cache. Data: ['id' => string]
 *
 * @phpstan-import-type ClassMeta from ContainerCache
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

    /** @var array<string, ClassMeta> Constructor signatures this container found by Reflection */
    private array $analyzedMeta = [];

    /** @var array<string, ClassMeta> Constructor signatures read from the cache file */
    private array $loadedMeta = [];

    private ?ContainerCache $cache = null;
    private ?string $cacheFile = null;
    private ?string $cacheSignature = null;
    private bool $debug = false;
    private bool $cacheLoaded = false;
    private bool $cacheDirty = false;

    /** @var array<string, true> Entries currently being created (for circular dependency detection) */
    private array $resolving = [];

    private bool $reportingError = false;

    /**
     * Create a new Container instance.
     *
     * Config precedence: $config > $_ENV > getenv() > default
     *
     * 'cacheFile' is taken literally when present: null (or '') disables caching
     * regardless of the environment.
     *
     * @param array{debug?: bool, cacheFile?: string|null, cacheSignature?: string|null} $config
     */
    public function __construct(array $config = [])
    {
        // Config precedence: $config > $_ENV > getenv() > default (consistent with pdo-wrapper/php-router)
        if (array_key_exists('debug', $config)) {
            $this->debug = (bool) $config['debug'];
        } else {
            $this->debug = filter_var(self::env('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOL)
                ?: in_array(self::env('APP_ENV') ?? '', ['local', 'dev', 'development'], true);
        }

        $this->cacheFile = array_key_exists('cacheFile', $config)
            ? self::blankToNull($config['cacheFile'])
            : self::cacheEnv('CONTAINER_CACHE_FILE');
        // The key keeps its fallback: there is nothing to switch off with it, and a null from
        // the caller ($_ENV['...'] ?? null) used to mean "take it from the environment"
        $this->cacheSignature = self::blankToNull($config['cacheSignature'] ?? null)
            ?? self::cacheEnv('CONTAINER_CACHE_KEY');

        $this->rebuildCache();
    }

    /**
     * An empty value means "not set".
     */
    private static function blankToNull(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Cache setting from the environment ($_ENV > getenv()); an empty one (KEY= in a .env file) is skipped.
     */
    private static function cacheEnv(string $key): ?string
    {
        $value = $_ENV[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        // Only asked when $_ENV has nothing, as before
        $value = getenv($key);

        return $value === false ? null : self::blankToNull($value);
    }

    /**
     * Get environment variable value ($_ENV > getenv() fallback).
     */
    private static function env(string $key): ?string
    {
        // $_ENV is thread-safe, preferred
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        // getenv() fallback for legacy compatibility
        $value = getenv($key);

        return $value !== false ? $value : null;
    }

    /**
     * Factory method for fluent creation.
     *
     * @param array{debug?: bool, cacheFile?: string|null, cacheSignature?: string|null} $config
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Enable caching (fluent API).
     *
     * @param string $file Path to cache file
     * @param string|null $signature HMAC key for integrity verification (required in production);
     *                               null keeps the key that is already configured
     */
    public function enableCache(string $file, ?string $signature = null): self
    {
        $this->cacheFile = self::blankToNull($file);
        $this->cacheSignature = self::blankToNull($signature) ?? $this->cacheSignature;
        // What was read from the previous file does not carry over; what this container analyzed does
        $this->loadedMeta = [];
        $this->cacheLoaded = false;
        $this->rebuildCache();
        return $this;
    }

    /**
     * Disable caching (fluent API). Nothing is read from or written to a cache file afterwards.
     */
    public function disableCache(): self
    {
        $this->cacheFile = null;
        $this->loadedMeta = [];
        $this->cacheLoaded = false;
        $this->cacheDirty = false;
        $this->rebuildCache();
        return $this;
    }

    /**
     * Enable/disable debug mode (fluent API).
     *
     * @param bool $debug Enable debug mode (disables caching)
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        $this->loadedMeta = [];
        $this->cacheLoaded = false;
        $this->cacheDirty = false;
        $this->rebuildCache();
        return $this;
    }

    /**
     * Register a service factory.
     *
     * @param string $id The class name or identifier
     * @param callable $factory A closure that returns the instance: fn(Container $c) => new Service(...)
     */
    public function set(string $id, callable $factory): void
    {
        $this->definitions[$id] = $factory;
    }

    /**
     * Bind an interface to a concrete implementation.
     *
     * Uses a lightweight string mapping instead of closures for better memory efficiency.
     *
     * @param string $interface The interface or abstract class name
     * @param class-string $implementation The concrete class name
     */
    public function bind(string $interface, string $implementation): self
    {
        $this->aliases[$interface] = $implementation;
        return $this;
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

            // 2. Manual Definition (set() overrides bind()) or 4. Autowiring
            if (isset($this->definitions[$target]) || !isset($this->aliases[$target]) || $this->aliases[$target] === $target) {
                $instance = $this->make($target, array_keys($aliases));
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
        while (!array_key_exists($id, $this->instances) && !isset($this->definitions[$id])) {
            if (!isset($this->aliases[$id]) || $this->aliases[$id] === $id) {
                return class_exists($id) && (new ReflectionClass($id))->isInstantiable();
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
     * Save cache to disk (call at end of bootstrap/request).
     *
     * Only writes if new classes were resolved during this request or a file from 1.0.x has to be replaced.
     *
     * @throws CacheException If the file cannot be written
     */
    public function saveCache(): void
    {
        if ($this->cache !== null && $this->cacheDirty && !$this->debug) {
            $this->cache->save($this->analyzedMeta + $this->loadedMeta);
            $this->cacheDirty = false;
        }
    }

    /**
     * Clear the cache.
     *
     * @return bool True if a file was deleted
     */
    public function clearCache(): bool
    {
        $this->analyzedMeta = [];
        $this->loadedMeta = [];
        $this->cacheLoaded = false;
        $this->cacheDirty = false;
        return $this->cache?->clear() ?? false;
    }

    /**
     * Rebuild the ContainerCache instance from current state.
     */
    private function rebuildCache(): void
    {
        if ($this->cacheFile !== null) {
            $this->cache = new ContainerCache($this->cacheFile, $this->cacheSignature, !$this->debug);
        } else {
            $this->cache = null;
        }
    }

    private function loadCache(): void
    {
        if ($this->cacheLoaded || $this->cache === null || $this->debug) {
            return;
        }

        $this->cacheLoaded = true;
        $data = $this->cache->load();

        if ($data !== null) {
            $this->loadedMeta = $data;
        } elseif ($this->cache->exists()) {
            // A file that cannot be used (written by 1.0.x) is replaced by the next saveCache()
            $this->cacheDirty = true;
        }
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

    private static function describe(\Throwable $e): string
    {
        return sprintf('%s in %s:%d', $e::class, $e->getFile(), $e->getLine());
    }

    private function runFactory(string $id, callable $factory): mixed
    {
        try {
            return $factory($this);
        } catch (\Throwable $e) {
            $this->report($id, $e);
            throw new ContainerException(
                "Error while creating service '$id': " . $e->getMessage(),
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

        try {
            $this->loadCache();
        } catch (ContainerException $e) {
            throw $this->fail($id, $e);
        }

        // What this container analyzed itself is current; the file may predate a code change
        $meta = $this->analyzedMeta[$id] ?? $this->loadedMeta[$id] ?? null;

        if ($meta !== null) {
            if ($this->cache !== null) {
                $this->trigger('cacheHit', ['id' => $id]);
            }

            return $this->build($id, $meta);
        }

        if ($this->cache !== null) {
            $this->trigger('cacheMiss', ['id' => $id]);
        }
        [$meta, $parameters] = $this->analyze($id);

        return $this->build($id, $meta, $parameters);
    }

    /**
     * Read the constructor signature. Decides nothing about wiring: the same metadata is valid
     * for every container configuration, which is what makes it cacheable.
     *
     * @param class-string $id
     *
     * @return array{ClassMeta, list<ReflectionParameter>} The metadata (default values are added by build()) and the parameters
     */
    private function analyze(string $id): array
    {
        $reflector = new ReflectionClass($id);

        if (!$reflector->isInstantiable()) {
            throw $this->fail($id, new ContainerException("Class '$id' is not instantiable (abstract or interface)."));
        }

        $meta = ['class' => $id, 'dependencies' => [], 'defaults' => [], 'optional' => []];

        $parameters = $reflector->getConstructor()?->getParameters() ?? [];

        foreach ($parameters as $index => $param) {
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
                $meta['dependencies'][$index] = null;
                continue;
            }

            // Primitives (int, string, bool) cannot be autowired unless default value exists
            if ($type->isBuiltin()) {
                if (!$param->isDefaultValueAvailable()) {
                    throw $this->fail($id, new ContainerException(
                        "Cannot resolve primitive parameter '{$param->getName()}' (type: {$type->getName()}) in class '$id'. Use set() to define this service manually."
                    ));
                }
                $meta['dependencies'][$index] = null;
                continue;
            }

            // It's a class/interface dependency
            $meta['dependencies'][$index] = $type->getName();
            if ($param->isOptional()) {
                $meta['optional'][$index] = true;
            }
        }

        return [$meta, $parameters];
    }

    /**
     * Wire the dependencies and create the instance. Cold and warm resolution both end here.
     *
     * Defaults are evaluated here, in parameter order and only if they are used: a default can be
     * an object (new in initializer) whose constructor relies on what was created before it.
     *
     * @param class-string $id
     * @param ClassMeta $meta
     * @param list<ReflectionParameter>|null $parameters Set if the metadata was just analyzed and is not stored yet
     */
    private function build(string $id, array $meta, ?array $parameters = null): object
    {
        $fresh = $parameters !== null;
        $arguments = [];
        // Metadata saved through ContainerCache directly may come without the key (1.0.x did not need it)
        $meta += ['defaults' => []];

        foreach ($meta['dependencies'] as $index => $depId) {
            // No class dependency: the default, from the metadata if it is already known
            if ($depId === null) {
                if (!array_key_exists($index, $meta['defaults'])) {
                    $meta['defaults'][$index] = $this->defaultValue($id, $meta['class'], $index, $parameters);
                }
                $arguments[] = $meta['defaults'][$index];
                continue;
            }

            // An optional dependency the container cannot provide: its default
            if (isset($meta['optional'][$index]) && !$this->has($depId)) {
                $arguments[] = $this->defaultValue($id, $meta['class'], $index, $parameters);
                continue;
            }

            try {
                $arguments[] = $this->get($depId);
            } catch (NotFoundException $e) {
                $parameters ??= self::parameters($meta['class']);
                $name = isset($parameters[$index]) ? $parameters[$index]->getName() : "#$index";
                throw new ContainerException(
                    "Cannot resolve dependency '{$depId}' for parameter '{$name}' in class '$id'.",
                    0,
                    $e
                );
            }
        }

        // Remember the signature, unless a default is an object the data file cannot hold
        if ($fresh && ContainerCache::isCacheable($meta['defaults'])) {
            $this->analyzedMeta[$id] = $meta;
            $this->cacheDirty = true;
        }

        try {
            return new $meta['class'](...$arguments);
        } catch (\Throwable $e) {
            throw $this->instantiationFailed($id, $e);
        }
    }

    /**
     * Evaluate the default of a constructor parameter. It can run code, so it can fail like a constructor.
     *
     * @param class-string $class
     * @param list<ReflectionParameter>|null $parameters Looked up here if not known yet
     *
     * @param-out list<ReflectionParameter> $parameters
     */
    private function defaultValue(string $id, string $class, int $index, ?array &$parameters): mixed
    {
        $parameters ??= self::parameters($class);

        if (!isset($parameters[$index]) || !$parameters[$index]->isDefaultValueAvailable()) {
            throw $this->fail($id, new ContainerException(
                "Cannot resolve parameter #$index in class '$id': the cached metadata is outdated. Clear the cache."
            ));
        }

        try {
            return $parameters[$index]->getDefaultValue();
        } catch (\Throwable $e) {
            throw $this->instantiationFailed($id, $e);
        }
    }

    private function instantiationFailed(string $id, \Throwable $e): ContainerException
    {
        $this->report($id, $e);

        return new ContainerException(
            "Failed to instantiate '$id': " . $e->getMessage(),
            0,
            $e,
            self::describe($e)
        );
    }

    /**
     * @return list<ReflectionParameter> Empty if the class named by outdated metadata is gone
     */
    private static function parameters(string $class): array
    {
        return class_exists($class) ? (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] : [];
    }
}
