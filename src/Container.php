<?php

declare(strict_types=1);

namespace Sodaho\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use Sodaho\Container\Cache\ContainerCache;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;
use Sodaho\Container\Traits\HasHooks;

/**
 * Lightweight PSR-11 container with autowiring and optional caching.
 *
 * Hooks:
 * - 'resolve': Triggered when a new instance is created. Data: ['id' => string, 'instance' => object]
 * - 'error': Triggered on exceptions. Data: ['id' => string, 'exception' => Throwable]
 * - 'cacheHit': Triggered when class metadata is found in cache. Data: ['id' => string]
 * - 'cacheMiss': Triggered when class metadata is not in cache. Data: ['id' => string]
 */
class Container implements ContainerInterface
{
    use HasHooks;

    /** @var array<string, callable> */
    private array $definitions = [];

    /** @var array<string, class-string> Interface -> Implementation mappings */
    private array $aliases = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, array{class: class-string, dependencies: array<int, string|null>, defaults: array<int, mixed>}> */
    private array $resolvedMeta = [];

    private ?ContainerCache $cache = null;
    private ?string $cacheFile = null;
    private ?string $cacheSignature = null;
    private bool $debug = false;
    private bool $cacheLoaded = false;
    private bool $cacheDirty = false;

    /** @var array<string, true> Entries currently being created (for circular dependency detection) */
    private array $resolving = [];

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
        $this->resolvedMeta = [];
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
     * @param string $id Identifier of the entry to look for.
     *
     * @throws NotFoundException No entry was found for this identifier.
     * @throws ContainerException Error while retrieving the entry.
     *
     * @return mixed Entry.
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
                throw $this->circular([...array_keys($aliases), $target]);
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
     * Only writes if new classes were resolved during this request.
     */
    public function saveCache(): void
    {
        if ($this->cache !== null && $this->cacheDirty && !$this->debug) {
            $this->cache->save($this->resolvedMeta);
            $this->cacheDirty = false;
        }
    }

    /**
     * Clear the cache.
     *
     * @return bool True if cleared
     */
    public function clearCache(): bool
    {
        $this->resolvedMeta = [];
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
            $this->resolvedMeta = $data;
        } elseif ($this->cache->exists()) {
            // A file that cannot be used (written by 1.0.x) is replaced by the next saveCache()
            $this->cacheDirty = true;
        }
    }

    /**
     * Create the entry for an id that is not an alias: run its factory or autowire it.
     *
     * @param list<string> $via Aliases that led here (for the circular dependency message)
     */
    private function make(string $id, array $via): mixed
    {
        if (isset($this->resolving[$id])) {
            throw $this->circular([...$via, $id]);
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

    private static function describe(\Throwable $e): string
    {
        return sprintf('%s in %s:%d', $e::class, $e->getFile(), $e->getLine());
    }

    private function runFactory(string $id, callable $factory): mixed
    {
        try {
            return $factory($this);
        } catch (\Throwable $e) {
            $this->trigger('error', ['id' => $id, 'exception' => $e]);
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
            throw new NotFoundException("Class or service '$id' not found.");
        }

        /** @var class-string $id */

        // Try cache first
        $this->loadCache();

        if (isset($this->resolvedMeta[$id])) {
            if ($this->cache !== null) {
                $this->trigger('cacheHit', ['id' => $id]);
            }
            return $this->buildFromCache($id);
        }

        if ($this->cache !== null) {
            $this->trigger('cacheMiss', ['id' => $id]);
        }

        return $this->resolveWithReflection($id);
    }

    /** @param class-string $id */
    private function buildFromCache(string $id): object
    {
        $meta = $this->resolvedMeta[$id];
        $dependencies = [];

        foreach ($meta['dependencies'] as $index => $depId) {
            if ($depId === null) {
                // Use cached default value
                $dependencies[] = $meta['defaults'][$index] ?? null;
            } else {
                $dependencies[] = $this->get($depId);
            }
        }

        return new $meta['class'](...$dependencies);
    }

    /** @param class-string $id */
    private function resolveWithReflection(string $id): object
    {
        $reflector = new ReflectionClass($id);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class '$id' is not instantiable (abstract or interface).");
        }

        $constructor = $reflector->getConstructor();

        // No constructor? Simple instantiation.
        if ($constructor === null) {
            $this->resolvedMeta[$id] = [
                'class' => $id,
                'dependencies' => [],
                'defaults' => [],
            ];
            $this->cacheDirty = true;

            return new $id();
        }

        // Resolve dependencies and build metadata
        $dependencies = [];
        $depIds = [];
        $defaults = [];

        foreach ($constructor->getParameters() as $index => $param) {
            $resolved = $this->resolveParameter($param, $id);
            $dependencies[] = $resolved['value'];
            $depIds[] = $resolved['depId'];
            if ($resolved['depId'] === null) {
                $defaults[$index] = $resolved['value'];
            }
        }

        // Cache the resolution metadata, unless a default is an object the data file cannot hold
        $meta = [
            'class' => $id,
            'dependencies' => $depIds,
            'defaults' => $defaults,
        ];
        if (ContainerCache::isCacheable($meta)) {
            $this->resolvedMeta[$id] = $meta;
            $this->cacheDirty = true;
        }

        try {
            return $reflector->newInstanceArgs($dependencies);
        } catch (\Throwable $e) {
            $this->trigger('error', ['id' => $id, 'exception' => $e]);
            throw new ContainerException(
                "Failed to instantiate '$id': " . $e->getMessage(),
                0,
                $e,
                self::describe($e)
            );
        }
    }

    /** @return array{value: mixed, depId: string|null} */
    private function resolveParameter(ReflectionParameter $param, string $classId): array
    {
        // Variadic parameters (...$args) are not supported for autowiring
        if ($param->isVariadic()) {
            throw new ContainerException(
                "Cannot resolve variadic parameter '...{$param->getName()}' in class '$classId'. Use set() to define this service manually."
            );
        }

        $type = $param->getType();

        // No type hint, Union Types or Intersection Types (not supported for simplicity)
        if (!$type || $type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            if ($param->isDefaultValueAvailable()) {
                return ['value' => $param->getDefaultValue(), 'depId' => null];
            }
            throw new ContainerException(
                "Cannot resolve parameter '{$param->getName()}' in class '$classId'. No type hint, union type, or intersection type. Use set() to define this service manually."
            );
        }

        /** @var ReflectionNamedType $type */

        // Primitives (int, string, bool) cannot be autowired unless default value exists
        if ($type->isBuiltin()) {
            if ($param->isDefaultValueAvailable()) {
                return ['value' => $param->getDefaultValue(), 'depId' => null];
            }
            throw new ContainerException(
                "Cannot resolve primitive parameter '{$param->getName()}' (type: {$type->getName()}) in class '$classId'. Use set() to define this service manually."
            );
        }

        // It's a class/interface dependency -> Recursion!
        $depClassName = $type->getName();
        try {
            return ['value' => $this->get($depClassName), 'depId' => $depClassName];
        } catch (NotFoundException $e) {
            // Optional dependency?
            if ($param->isOptional()) {
                return ['value' => $param->getDefaultValue(), 'depId' => null];
            }
            throw new ContainerException(
                "Cannot resolve dependency '{$depClassName}' for parameter '{$param->getName()}' in class '$classId'.",
                0,
                $e
            );
        }
    }
}
