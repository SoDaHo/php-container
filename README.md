# php-container

Lightweight PSR-11 dependency injection container for PHP. Autowiring, caching, zero bloat.

## Why This Library?

**What it does:**
- PSR-11 container with constructor autowiring
- Reflection metadata caching for production performance
- HMAC-signed cache files to prevent RCE in shared hosting
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

## Caching

The container can cache Reflection metadata to avoid analyzing classes on every request.

### Enable via Config

```php
$container = new Container([
    'cacheFile' => '/var/cache/container.php',
    'cacheSignature' => $_ENV['CONTAINER_CACHE_KEY'],  // Required in production!
    'debug' => false,
]);

// At end of bootstrap/request
$container->saveCache();
```

**Security:** A signature key is **required** when caching is enabled (`debug=false`). This prevents RCE attacks via tampered cache files. See [Security](#security) section below.

### Enable via Fluent API

```php
$container = Container::create()
    ->setDebug(false)
    ->enableCache('/var/cache/container.php', $_ENV['CONTAINER_CACHE_KEY']);

// ... resolve services ...

$container->saveCache();
```

### Enable via Environment Variables

```env
CONTAINER_CACHE_FILE=/var/cache/container.php
CONTAINER_CACHE_KEY=your-secret-key-here  # Required in production!
APP_DEBUG=false
```

```php
$container = new Container();  // Reads from $_ENV / getenv() automatically
```

Generate a secure key: `php -r "echo bin2hex(random_bytes(32));"`

### Configuration Priority

**Priority:** `$config` array > `$_ENV` > `getenv()` > default

The library checks `$_ENV` first (thread-safe), then falls back to `getenv()` for legacy compatibility. Use a library like [sodaho/env-loader](https://github.com/sodaho/env-loader) to load `.env` files into `$_ENV`.

### How Caching Works

1. **First request:** Reflection analyzes classes, stores metadata
2. **Following requests:** Metadata loaded from cache, no Reflection needed
3. **OPcache:** Cache file is PHP code, optimized by OPcache

The cache stores "build instructions" (which dependencies each class needs), not the instances themselves.

### Cache Management

```php
// Save cache (only writes if new classes were resolved)
$container->saveCache();

// Clear cache
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
| `resolve` | New instance created | `['id' => string, 'instance' => object]` |
| `error` | Exception during resolution | `['id' => string, 'exception' => Throwable]` |
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

**Note:** Hooks only fire when a new instance is created. Singleton cache hits (returning an already-resolved instance) do not trigger `resolve`.

## Security

### Cache Signature Key (Required in Production)

When caching is enabled (`debug=false`), a signature key is **required**. This prevents Remote Code Execution (RCE) attacks via tampered cache files in shared hosting environments.

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

**Why?** The cache file contains PHP code that gets executed via `require`. An attacker with write access to the cache file could inject malicious code. The HMAC-SHA256 signature ensures the file hasn't been modified.

### Exception Debug Messages

Exceptions have two messages:
- **User message:** Safe for end users, returned by `getMessage()`
- **Debug message:** Contains technical details for logging, returned by `getDebugMessage()`

```php
try {
    $container->get(SomeService::class);
} catch (ContainerException $e) {
    // Show to user
    echo $e->getMessage();  // "Cache signature key is required..."

    // Log for debugging
    error_log($e->getDebugMessage());  // "Provide key via CONTAINER_CACHE_KEY..."
}
```

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
    // Cache read/write error or signature mismatch
} catch (ContainerException $e) {
    // Any other container error (not instantiable, unresolvable parameter, etc.)
}
```

| Exception | When |
|-----------|------|
| `NotFoundException` | Class doesn't exist or service not defined |
| `ContainerException` | Class not instantiable, unresolvable parameter, factory error, circular dependency |
| `CacheException` | Cache write failed, directory not writable, invalid signature, missing signature key |

## Limitations

The container is intentionally minimal. It does **not** support:

| Feature | Status | Alternative |
|---------|--------|-------------|
| Interface binding | **Supported** | `bind()` method |
| Autowiring | **Supported** | Automatic via Reflection |
| Singleton | **Supported** | Default behavior |
| Factories | **Supported** | `set()` method |
| Caching | **Supported** | `enableCache()` / config |
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
