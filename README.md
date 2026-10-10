# php-container

Lightweight PSR-11 dependency injection container for PHP. Autowiring, zero bloat.

## Why This Library?

**What it does:**
- PSR-11 container with constructor autowiring
- Nothing to configure: no options, no environment variables, no files
- Zero dependencies beyond `psr/container`

**What it deliberately does not:**
- Attribute-based configuration
- Caching of Reflection results (measured: it costs more than it saves)
- Lazy objects (PHP 8.4 has them built in: return `ReflectionClass::newLazyProxy()` from a `set()` factory)
- Compiler passes
- Tagged services

If you need those, use Symfony DI or PHP-DI.

## Installation

```bash
composer require sodaho/container
```

Coming from 1.x? The CHANGELOG lists every line to change under "Upgrading from 1.x".

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

The last `set()` or `bind()` for an id wins. Register definitions and bindings before the first `get()`. While `get()` runs, `set()` and `bind()` throw a `ContainerException`, for any id: a factory, a hook or a destructor that registers something then would change what some entries get and not others. Entries are singletons: a `set()` or `bind()` for an entry that has been created throws as well, and so does one for a type an entry was created with the default value for (see Optional Dependencies).

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

`bind()` checks the classes it is given, as far as they exist: the implementation must implement or extend the interface, and the interface must be written as declared (another case or a leading `\` throws, since `get()` with that spelling would not find the binding). The implementation is stored as declared, so it is the same entry `get()` of that class returns. A name that does not exist (a typo) is accepted and fails at `get()`, as does an interface bound to itself. A chain of bindings (`A` to `B`, `B` to `C`) has to go from interface to subtype at each step, so classes that exist cannot form a cycle.

### Singleton Behavior

All resolved instances are cached (singleton pattern):

```php
$container = new Container();

$logger1 = $container->get(Logger::class);
$logger2 = $container->get(Logger::class);

$logger1 === $logger2; // true - same instance
```

The id is used as given: `Logger::class`, `'\\' . Logger::class` and a differently cased spelling are three entries. Use `::class`. Constructor parameter types are different: a type written in another case than the class is declared still gets the entry, binding or factory of that class, once PHP has the class loaded. `bind()` loads the classes it is given; a class that is not loaded yet is found by an autoloader only if it accepts that spelling (a PSR-4 autoloader on a case-sensitive file system does not), otherwise the type stays as written.

### Optional Dependencies

A constructor parameter with a default value gets that default when the container cannot provide the type: an interface or abstract class without binding, or an enum. If something is bound, the binding is used and the default (which may be `new Foo()`) is not evaluated; a binding that cannot be resolved (a typo in the class name) throws.

```php
class Mailer {
    public function __construct(
        private ?LoggerInterface $logger = null,   // null unless LoggerInterface is bound
        private Priority $priority = Priority::Normal,
    ) {}
}
```

A concrete class that exists but cannot be built (for example because it needs a string) is a wiring error and throws, default or not.

A union of one class with `null` or `false` (`LoggerInterface|false $logger = false`) counts as that class, like `?LoggerInterface`; without a default it is required like `LoggerInterface`. Any other union type gets its default, and throws without one.

Once an entry has been created with the default, `set()` and `bind()` for that type throw a `ContainerException`: the entry would keep its default and never see the definition. Register them before the first `get()`.

### The Container Itself

The container autowires no container, itself included: autowiring would create a new, empty one, without the factories, bindings and hooks of the container that was asked. `get()` throws a `ContainerException` for a class that implements `ContainerInterface`, and for a constructor parameter that asks for a container nothing is registered for, optional or not. Register the container under the type the parameter names:

```php
$container->set(ContainerInterface::class, fn (Container $c) => $c);
```

PSR-11 advises against handing the container to services; where you can, pass the services themselves.

### Checking for an Entry

`has($id)` is true when `get($id)` has something to return: a `set()` definition, a `bind()` chain that ends at one or at a class, or a class that can be instantiated. It follows bindings but creates nothing, so it does not check the constructor's parameters: `get()` can still fail with a `ContainerException`. When `has()` is false, `get()` throws a `NotFoundException`. An id on a cycle of bindings counts as known but broken: `has()` is true, and `get()` throws a `ContainerException`.

```php
if ($container->has(CacheInterface::class)) {
    $cache = $container->get(CacheInterface::class);
}
```

### Static Analysis

`get()` is typed for PHPStan: with a class name it returns that class, with any other id `mixed`.

```php
$logger = $container->get(Logger::class);   // Logger
$name = $container->get('app.name');        // mixed
```

This holds for every way an entry is made. A `set()` factory registered under a class or interface name has to return an instance of it, and so has the entry a binding ends at: `get()` throws a `ContainerException` otherwise (`null` included). PHPStan reports a type check on the result (`assert($logger instanceof Logger)`) as always true, and rightly so.

## Hooks

The container fires events at key points, allowing you to add logging, monitoring, or debugging without modifying your services.

### Available Events

| Event | When | Data |
|-------|------|------|
| `resolve` | New entry created | `['id' => string, 'instance' => mixed]` (whatever a `set()` factory returned) |
| `error` | `get()` fails | `['id' => string, 'exception' => Throwable]` |

`on()` throws a `ContainerException` for any other event name. A subclass that fires events of its own with `trigger()` lists them: `protected const array EVENTS = [...parent::EVENTS, 'boot'];`

### Usage

```php
$container = new Container();

// Log all resolved services
$container->on('resolve', function (array $data) {
    error_log("Resolved: {$data['id']}");
});

// Log errors
$container->on('error', function (array $data) {
    error_log("Container error for {$data['id']}: " . $data['exception']->getMessage());
});
```

**Note:** Hooks only fire when a new instance is created. Singleton cache hits (returning an already-resolved instance) do not trigger `resolve`. An id resolved through `bind()` fires `resolve` for the implementation, not for the interface. `resolve` fires once the entry exists, so dependencies come first: for a `Controller` that needs a `Service` that needs a `Logger`, the order is `Logger`, `Service`, `Controller`.

`error` fires once where the container detects the failure, also when the caller catches the exception. An exception thrown by a `resolve` hook is not reported where it happens; when it reaches a factory that asked for the entry, it is reported as that factory's failure. `id` is the entry that could not be created (a missing dependency, not the class that needs it), `exception` is the original exception of a factory or constructor, otherwise the container's own. A factory that fails because an entry it requested failed is reported as well. An `error` hook may use the container, but should catch what `get()` throws there: while an `error` hook runs, further failures are not reported to any `error` hook, and asking for the entry that is just being created fails as a circular dependency. `set()` and `bind()` throw there, as anywhere while `get()` runs: register a replacement after `get()` has failed.

Hooks fail hard: an exception thrown inside a `resolve` hook leaves `get()` as it is (inside a `set()` factory it is wrapped like anything else the factory throws). One exception: a `NotFoundExceptionInterface` is wrapped in a `ContainerException` (`Resolve hook failed for 'X'.`, the hook's exception in `getPrevious()`), because `has()` is true for the entry and PSR-11 keeps `NotFoundExceptionInterface` for the ids `has()` is false for. An entry whose `resolve` hook throws is not kept, nor is any entry created while that hook ran: one of them may hold the instance that never passed the hook (a class that needs it, a binding to it, a factory that asked for it). The next `get()` creates them anew, running factories, constructors and hooks again, and `set()` or `bind()` for them are accepted until then. Entries created before, the dependencies of the entry among them, stay.

A throwing `error` hook (a log sink that is down) does not hide the failure: `get()` throws an exception of the same class with the same message, the exception it was about to throw in `getPrevious()`, and what the hook threw in `getDebugMessage()` (`The error hook threw Class in file:line: message`). The hook's exception itself is not kept.

## Security

### Never Pass User Input to `get()` or `has()`

`get($id)` creates any autoloadable class whose constructor it can satisfy, and runs that constructor. An id taken from a request (a route parameter, a query string) lets the caller choose the class. Map user input to a fixed list of ids yourself.

### Exception Messages

`getMessage()` names ids, classes and parameters, and nothing else. When a factory or constructor throws, the container's message says which entry failed, not what the exception said: that text may contain connection strings or paths. Control characters in an id are escaped there (a line break shows as `\x0A`), so an id cannot add a line to a log; the `error` hook receives the id unchanged.

The details are in `getDebugMessage()`: `Class in file:line: message` for the wrapped exception and every exception behind it, joined by ` <- `. It is `null` for failures the container detects itself, unless the `error` hook threw while they were reported. The original exception is available via `getPrevious()`, and the `error` hook receives it directly, unchanged: its message, and its trace with the arguments of every call unless `zend.exception_ignore_args` is on (it is in `php.ini-production`, not in development). Log these, show end users a generic message.

## Exceptions

All exceptions implement PSR-11 interfaces:

```php
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

try {
    $service = $container->get(SomeService::class);
} catch (NotFoundException $e) {
    // Nothing the container can create for this id (has() is false)
} catch (ContainerException $e) {
    // Any other container error (unresolvable parameter, missing dependency, etc.)
}
```

| Exception | When |
|-----------|------|
| `NotFoundException` | `get()` for an id `has()` is false for: no such class or service, an interface without binding, a class that cannot be instantiated (abstract, an enum, constructor not public), also at the end of a binding. |
| `ContainerException` | `get()`: a container to autowire (see The Container Itself), unresolvable parameter, a dependency the container cannot create (the `NotFoundException` is in `getPrevious()`), factory or constructor error, circular dependency (through constructors, bindings or factories), a factory under a class name that returns something else, a binding that ends at an entry of another type. `set()` / `bind()`: called while `get()` runs, the entry has been created, or an entry was created with the default value for this type. `bind()`: the interface is written otherwise than declared, or the implementation neither implements nor extends it. `on()`: unknown event. Constructor and `create()`: a config array that is not empty. |

`NotFoundException` extends `ContainerException`: catching `ContainerException` catches both.

An exception thrown while a class is loaded (by an autoloader, or a syntax error in the class file) is not the container's: `get()` and `has()` let it pass unchanged.

## Limitations

The container is intentionally minimal:

| Feature | Status | Alternative |
|---------|--------|-------------|
| Interface binding | **Supported** | `bind()` method |
| Autowiring | **Supported** | Automatic via Reflection |
| Singleton | **Supported** | Default behavior |
| Factories | **Supported** | `set()` method |
| Optional dependencies | **Supported** | Default value if the type cannot be provided |
| Union types | A class with `null` or `false` like `?T`, others default only | Use `set()` for manual definition |
| Intersection types | Default only | Use `set()` for manual definition |
| Attributes | Not supported | Use `set()` for configuration |
| Tagged services | Not supported | Not needed for simple DI |
| Lazy objects | Not built in | PHP's own lazy objects (8.4) in a `set()` factory |
| Compiler passes | Not supported | Framework territory |

### Concurrency and Copies

A container is meant for one request at a time. Fibers or coroutines that share one see each other's entries in creation: a second `get()` of an entry the first has not finished yet fails as a circular dependency, and `set()` or `bind()` throws while another one is inside `get()`. Use one container per request or coroutine, or create the shared entries before they start.

`clone $container` copies the registrations and shares the entries created so far; entries created afterwards exist once in each copy. Hooks that captured the original container (`use ($container)`) keep using the original; factories get the container that runs them.

## Requirements

- PHP ^8.5
- psr/container ^2.0

## License

MIT

## Acknowledgments

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).
