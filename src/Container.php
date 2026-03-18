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
    private bool $debug = false;
    private bool $cacheLoaded = false;
    private bool $cacheDirty = false;

    /** @var array<string, true> Classes currently being resolved (for circular dependency detection) */
    private array $resolving = [];

    /**
     * Create a new Container instance.
     *
     * Config precedence: $config > $_ENV > getenv() > default
     *
     * @param array{debug?: bool, cacheFile?: string, cacheSignature?: string} $config
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

        $cacheFile = $config['cacheFile'] ?? self::env('CONTAINER_CACHE_FILE');
        $cacheSignature = $config['cacheSignature'] ?? self::env('CONTAINER_CACHE_KEY');

        if ($cacheFile !== null) {
            $this->cache = new ContainerCache($cacheFile, $cacheSignature, !$this->debug);
        }
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
     * @param array{debug?: bool, cacheFile?: string, cacheSignature?: string} $config
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }

    /**
     * Enable caching (fluent API).
     *
     * @param string $file Path to cache file
     * @param string|null $signature Optional HMAC key
     */
    public function enableCache(string $file, ?string $signature = null): self
    {
        $this->cache = new ContainerCache($file, $signature, !$this->debug);
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
     * @throws NotFoundException No entry was found for **this** identifier.
     * @throws ContainerException Error while retrieving the entry.
     *
     * @return mixed Entry.
     */
    public function get(string $id): mixed
    {
        // 1. Singleton: Return existing instance
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        // 2. Manual Definition: Execute factory (set() overrides bind())
        if (isset($this->definitions[$id])) {
            $factory = $this->definitions[$id];
            try {
                $instance = $factory($this);
                $this->instances[$id] = $instance;
                $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
                return $instance;
            } catch (\Throwable $e) {
                $this->trigger('error', ['id' => $id, 'exception' => $e]);
                throw new ContainerException("Error while creating service '$id': " . $e->getMessage(), 0, $e);
            }
        }

        // 3. Alias: Resolve to implementation (bind() mappings)
        if (isset($this->aliases[$id])) {
            $instance = $this->get($this->aliases[$id]);
            $this->instances[$id] = $instance;
            return $instance;
        }

        // 4. Autowiring: Try to resolve class (with cache support)
        return $this->resolve($id);
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || isset($this->definitions[$id])
            || isset($this->aliases[$id])
            || class_exists($id);
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

    private function loadCache(): void
    {
        if ($this->cacheLoaded || $this->cache === null) {
            return;
        }

        $this->cacheLoaded = true;
        $data = $this->cache->load();

        if ($data !== null) {
            $this->resolvedMeta = $data;
        }
    }

    private function resolve(string $id): object
    {
        if (!class_exists($id)) {
            throw new NotFoundException("Class or service '$id' not found.");
        }

        // Circular dependency detection
        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $id;
            throw new ContainerException("Circular dependency detected: $chain");
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

        // Full Reflection resolve (with circular detection)
        $this->resolving[$id] = true;
        try {
            return $this->resolveWithReflection($id);
        } finally {
            unset($this->resolving[$id]);
        }
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

        $instance = new $meta['class'](...$dependencies);
        $this->instances[$id] = $instance;
        $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
        return $instance;
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

            $instance = new $id();
            $this->instances[$id] = $instance;
            $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
            return $instance;
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

        // Cache the resolution metadata
        $this->resolvedMeta[$id] = [
            'class' => $id,
            'dependencies' => $depIds,
            'defaults' => $defaults,
        ];
        $this->cacheDirty = true;

        try {
            $instance = $reflector->newInstanceArgs($dependencies);
            $this->instances[$id] = $instance;
            $this->trigger('resolve', ['id' => $id, 'instance' => $instance]);
            return $instance;
        } catch (\Throwable $e) {
            $this->trigger('error', ['id' => $id, 'exception' => $e]);
            throw new ContainerException("Failed to instantiate '$id': " . $e->getMessage(), 0, $e);
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
