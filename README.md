# php-container

Lightweight PSR-11 dependency injection container for PHP. Autowiring, caching, zero bloat.

## Why This Library?

**What it does:**
- PSR-11 container with constructor autowiring
- Reflection metadata caching for production performance
- Signed, data-only cache file (never executed)
- Zero dependencies beyond `psr/container`

**What it deliberately does not:**
- Attribute-based configuration
- Lazy proxies / code generation
- Compiler passes
- Tagged services

If you need those, use Symfony DI or PHP-DI.

## Installation

```bash
composer require sodaho/container
```

## Usage

### Basic Autowiring

```php
use Sodaho\Container\Container;

$container = new Container();

// Automatically resolves dependencies via Reflection
$controller = $container->get(UserController::class);
```

The container analyzes constructor parameters and recursively resolves all dependencies:

```php
class UserController {
    public function __construct(
        private UserService $userService,  // Auto-resolved
        private Logger $logger             // Auto-resolved
    ) {}
}
```

### Manual Definitions

For services that need configuration or primitives:

```php
$container = new Container();

// Factory receives the container for nested resolution
$container->set(Database::class, fn(Container $c) => new Database(
    host: $_ENV['DB_HOST'],
    logger: $c->get(Logger::class)
));

// Simple values
$container->set('app.name', fn() => 'My Application');
```

Register definitions and bindings before the first `get()` of that id: an entry that was already created is kept, a later `set()` or `bind()` for it has no effect.

### Interface Binding

Bind interfaces to concrete implementations:

```php
$container = new Container();

// Short syntax
$container->bind(LoggerInterface::class, FileLogger::class);
$container->bind(CacheInterface::class, RedisCache::class);

// Fluent chaining
$container = Container::create()
    ->bind(LoggerInterface::class, FileLogger::class)
    ->bind(CacheInterface::class, RedisCache::class);

// Now autowiring resolves interfaces automatically
$service = $container->get(PaymentService::class);
// PaymentService receives FileLogger for LoggerInterface parameter
```

### Singleton Behavior

All resolved instances are cached (singleton pattern):

```php
$container = new Container();

$logger1 = $container->get(Logger::class);
$logger2 = $container->get(Logger::class);

$logger1 === $logger2; // true - same instance
```

The id is used as given: `Logger::class`, `'\\' . Logger::class` and a differently cased spelling are three entries. Use `::class`.

### Optional Dependencies

A constructor parameter with a default value gets that default when the container cannot provide the type: an interface or abstract class without binding, or an enum. If something is bound, the binding is used and the default (which may be `new Foo()`) is not evaluated.

```php
class Mailer {
    public function __construct(
        private ?LoggerInterface $logger = null,   // null unless LoggerInterface is bound
        private Priority $priority = Priority::Normal,
    ) {}
}
```

A concrete class that exists but cannot be built (for example because it needs a string) is a wiring error and throws, default or not.

### Static Analysis

`get()` is typed for PHPStan: with a class name it returns that class, with any other id `mixed`.

```php
$logger = $container->get(Logger::class);   // Logger
$name = $container->get('app.name');        // mixed
```

This describes autowiring. A `set()` factory or `bind()` registered under a class name has to return an instance of that class; the container does not check it. PHPStan reports a type check on the result (`assert($logger instanceof Logger)`) as always true.

## Caching

**Deprecated: the cache will be removed in 2.0.** The container can store what Reflection found out about constructors and reuse it on the next request.

**Measure before you enable it.** Reflection is cheap and only runs for the classes a request uses. The cache file is read, verified and decoded as a whole on every request, so its cost grows with the number of classes in it. In our measurements (PHP 8.4, container built per request) the cache never paid off:

| Cached classes | Created per request | Without cache | With warm cache |
|---|---|---|---|
| 60 | 60 | 131 µs | 149 µs |
| 500 | 45 | 89 µs | 1.7 ms |
| 1000 | 45 | 90 µs | 3.3 ms |

### Enable via Config

```php
$container = new Container([
    'cacheFile' => '/var/cache/container.php',
    'cacheSignature' => $_ENV['CONTAINER_CACHE_KEY'],  // Required
    'debug' => false,
]);

// At end of bootstrap/request
$container->saveCache();
```

A signature key is **required** when caching is enabled (`debug=false`); an empty key counts as missing. See [Security](#security).

### Enable via Fluent API

```php
$container = Container::create()
    ->setDebug(false)
    ->enableCache('/var/cache/container.php', $_ENV['CONTAINER_CACHE_KEY']);

// ... resolve services ...

$container->saveCache();
```

`enableCache($file)` without a key keeps the key that is already configured. `disableCache()` turns caching off.

### Enable via Environment Variables

```env
CONTAINER_CACHE_FILE=/var/cache/container.php
CONTAINER_CACHE_KEY=your-secret-key-here
APP_DEBUG=false
```

```php
$container = new Container();  // Reads from $_ENV / getenv() automatically
```

Generate a secure key: `php -r "echo bin2hex(random_bytes(32));"`

### Configuration Priority

**Priority:** `$config` array > `$_ENV` > `getenv()` > default

`cacheFile` is taken literally when it is present in `$config`. To keep the environment out, say so:

```php
$container = new Container(['cacheFile' => null]);  // No cache, whatever CONTAINER_CACHE_FILE says
```

An empty value (`''`, or `CONTAINER_CACHE_FILE=` in a `.env` file) means "not set". For `cacheSignature`, "not set" falls back to the environment.

The library checks `$_ENV` first (thread-safe), then falls back to `getenv()` for legacy compatibility. Use a library like [sodaho/env-loader](https://github.com/sodaho/env-loader) to load `.env` files into `$_ENV`.

### How Caching Works

1. **First request:** Reflection analyzes classes, `saveCache()` stores the constructor signatures
2. **Following requests:** Signatures are loaded from the file, constructors are not analyzed again

The file holds data, not code, and is never executed. It stores which types and default values each constructor has, not instances and not wiring decisions: bindings are applied on every request. Scalar defaults are stored as they were when the class was cached.

- **Delete the file on every deploy.** Nothing ties an entry to the class file. After a constructor has changed, the outdated entry is used as it is: it can fail with a `ContainerException`, pass an old default value or put values into the wrong parameters.
- **Keep the file outside the web root.** Run as PHP it outputs nothing, served as a static file it shows class names and default values.
- **Not cached:** classes with a constructor parameter whose type is not a single class or interface (untyped, union, intersection, `object`, `mixed`) and whose default is an object other than an enum case (`$logger = new NullLogger()`). They are resolved by Reflection on every request.
- **Concurrent requests:** the file is written to a temporary file and moved into place, so readers never see a partial file. If several requests write at once, the last one wins; classes missing from its file are added by a later request.
- **Files written by 1.0.x** are never executed. They count as a miss and are replaced by `saveCache()` once a class was autowired, which needs a writable directory. 1.0.x cannot read the new format: delete the file before rolling back.

### Cache Management

```php
// Save cache (only writes if something changed)
$container->saveCache();  // Throws CacheException if the file cannot be written

// Clear cache (true if a file was deleted)
$container->clearCache();
```

## Debug Mode

Debug mode disables caching for development:

```php
// Explicit
$container = new Container(['debug' => true]);

// Or via fluent API
$container = Container::create()->setDebug(true);

// Or automatic detection via $_ENV
// APP_DEBUG=true or APP_ENV=local/dev/development
$container = new Container();  // Debug mode auto-enabled
```

## Hooks

The container fires events at key points, allowing you to add logging, monitoring, or debugging without modifying your services.

### Available Events

| Event | When | Data |
|-------|------|------|
| `resolve` | New entry created | `['id' => string, 'instance' => mixed]` (whatever a `set()` factory returned) |
| `error` | `get()` fails | `['id' => string, 'exception' => Throwable]` |
| `cacheHit` | Class metadata found in cache | `['id' => string]` |
| `cacheMiss` | Class metadata not in cache | `['id' => string]` |

### Usage

```php
$container = new Container();

// Log all resolved services
$container->on('resolve', function (array $data) {
    error_log("Resolved: {$data['id']}");
});

// Monitor cache performance
$container->on('cacheHit', fn($data) => $metrics->increment('container.cache.hit'));
$container->on('cacheMiss', fn($data) => $metrics->increment('container.cache.miss'));

// Log errors
$container->on('error', function (array $data) {
    error_log("Container error for {$data['id']}: " . $data['exception']->getMessage());
});
```

**Note:** Hooks only fire when a new instance is created. Singleton cache hits (returning an already-resolved instance) do not trigger `resolve`. An id resolved through `bind()` fires `resolve` for the implementation, not for the interface.

`error` fires once where the container detects the failure, also when the caller catches the exception: `id` is the entry that could not be created (a missing dependency, not the class that needs it), `exception` is the original exception of a factory or constructor, otherwise the container's own. A factory that fails because an entry it requested failed is reported as well. An `error` hook may use the container, but should catch what `get()` throws there: while an `error` hook runs, further failures are not reported to any `error` hook, and asking for the entry that is just being created fails as a circular dependency.

Hooks fail hard: the container does not catch an exception thrown inside a hook (inside a `set()` factory it is wrapped like anything else the factory throws). A throwing `error` hook replaces the exception `get()` was about to throw.

## Security

### Never Pass User Input to `get()` or `has()`

`get($id)` creates any autoloadable class whose constructor it can satisfy, and runs that constructor. An id taken from a request (a route parameter, a query string) lets the caller choose the class. Map user input to a fixed list of ids yourself.

### Cache Signature Key

When caching is enabled (`debug=false`), a signature key is **required**.

```php
// Production: signature key required
$container = new Container([
    'cacheFile' => '/var/cache/container.php',
    'cacheSignature' => $_ENV['CONTAINER_CACHE_KEY'],
    'debug' => false,
]);

// Development: debug mode disables caching, no key needed
$container = new Container([
    'cacheFile' => '/var/cache/container.php',
    'debug' => true,  // No signature required
]);
```

**Why?** The cache file tells the container which constructor arguments a class gets. The file is data and is never executed, and the HMAC-SHA256 signature covers every byte that is used, so a modified file is rejected with a `CacheException` instead of being trusted (a file in the 1.0.x format is ignored). The signature is bound to this library: a file signed with the same key by [sodaho/php-router](https://github.com/sodaho/php-router) does not verify.

The signature proves that the file was written with the key, not that it is current: an older file signed with the same key still verifies. Use a separate key for each application and environment.

A file with an invalid signature makes the first `get()` that needs the cache throw, once per container. If the application carries on, the container works without the cache and `saveCache()` replaces the file as soon as it has new metadata to write. After changing the key, delete the file (`clearCache()`).

### Exception Messages

`getMessage()` is written for logs, not for end users: when a factory or constructor throws, the container's message includes the message of that exception, which may contain connection strings or paths. `CacheException` messages for write errors contain the cache path. Show end users a generic message.

`getDebugMessage()` adds a hint where there is one (the origin `Class in file:line` for a wrapped exception, a remedy for cache errors) and is `null` otherwise. The original exception is available via `getPrevious()`.

## Exceptions

All exceptions implement PSR-11 interfaces:

```php
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;
use Sodaho\Container\Exception\CacheException;

try {
    $service = $container->get(SomeService::class);
} catch (NotFoundException $e) {
    // Class or service not found
} catch (CacheException $e) {
    // Invalid cache signature
} catch (ContainerException $e) {
    // Any other container error (not instantiable, unresolvable parameter, etc.)
}
```

| Exception | When |
|-----------|------|
| `NotFoundException` | Class doesn't exist or service not defined |
| `ContainerException` | Class not instantiable, unresolvable parameter, factory or constructor error, circular dependency (through constructors, bindings or factories) |
| `CacheException` | Missing signature key (constructor, `enableCache()`, `setDebug(false)`), invalid signature (`get()`), cache write failed or directory not writable (`saveCache()`) |

## Limitations

The container is intentionally minimal. It does **not** support:

| Feature | Status | Alternative |
|---------|--------|-------------|
| Interface binding | **Supported** | `bind()` method |
| Autowiring | **Supported** | Automatic via Reflection |
| Singleton | **Supported** | Default behavior |
| Factories | **Supported** | `set()` method |
| Caching | **Supported** | `enableCache()` / config |
| Optional dependencies | **Supported** | Default value if the type cannot be provided |
| Union types | Default only | Use `set()` for manual definition |
| Intersection types | Default only | Use `set()` for manual definition |
| Attributes | Not supported | Use `set()` for configuration |
| Tagged services | Not supported | Not needed for simple DI |
| Lazy proxies | Not supported | Would require code generation |
| Compiler passes | Not supported | Framework territory |

## Requirements

- PHP ^8.2
- psr/container ^2.0

## License

MIT

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).
