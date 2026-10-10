# sodaho/container

PSR-11 dependency injection container with constructor autowiring.

## Requirements

- PHP ^8.5
- psr/container ^2.0; the package provides `psr/container-implementation` 2.0

## Installation

```bash
composer require sodaho/container
```

## Quick start

```php
use Sodaho\Container\Container;

$container = Container::create()->bind(LoggerInterface::class, FileLogger::class);
$container->set(Database::class, fn (Container $c) => new Database($dsn, $c->get(LoggerInterface::class)));
$controller = $container->get(UserController::class); // its constructor dependencies are created and passed
```

## Reference

### Container (`Sodaho\Container\Container`, implements PSR-11 `ContainerInterface`)

| Signature | Description |
|---|---|
| `__construct(array $config = [])` | Creates an empty container; reads no environment variables and no files. |
| `static create(array $config = []): static` | `new static($config)`, for chaining. |
| `set(string $id, callable $factory): void` | Registers a factory; the first `get($id)` calls `$factory($container)`. |
| `bind(string $interface, string $implementation): self` | Maps an id to another id; `get()` follows the chain. |
| `get(string $id): mixed` | Returns the entry for the id, created on first use and kept (singleton). |
| `has(string $id): bool` | True when `get($id)` has an entry to return or create; creates nothing. |
| `on(string $event, callable $callback): static` | Registers a callback for the `resolve` or `error` hook. |

Registration:
- The last `set()` or `bind()` for an id wins. Ids are used as given: `Logger::class`, `'\\' . Logger::class` and
  another case are three ids. Use `::class`.
- `set()` and `bind()` throw while any `get()` runs (from a factory, a hook, a destructor), for an id whose entry
  exists, and for a type an entry got its default value for. Register before the first `get()`.
- `bind()` loads the implementation, then the interface. A name that exists must be written as declared (another
  case, a leading `\` or a `class_alias()` name throws); where both exist, the implementation must implement or
  extend the interface. A name no class has is taken as written: a typo in the implementation makes `get()` throw a
  `NotFoundException`, a typo in the interface registers a binding under the typo.

Autowiring, per constructor parameter:

| Parameter type | Gets |
|---|---|
| Class or interface with a factory, binding or entry | That entry; a binding that fails throws, default or not |
| Concrete instantiable class | The class, autowired; if it cannot be built, `get()` throws, default or not |
| Interface, abstract class or enum the container cannot provide | Its default; without one a `ContainerException` |
| `?T`, `T\|false`, `T\|false\|null` | Like `T` |
| Builtin type, other union, intersection type, no type | Its default; without one a `ContainerException` |
| Variadic | A `ContainerException` |
| A type that implements `ContainerInterface`, nothing registered | A `ContainerException`, optional or not |

- All parameters are checked before the first dependency is created; dependencies are created in parameter order.
  A default is evaluated only when it is used.
- A type written in another case than declared gets the class's entry once PHP has the class loaded.
- To pass the container itself: `$container->set(ContainerInterface::class, fn (Container $c) => $c);`
- `get()` of a class or interface name returns an instance of it or throws (a factory or binding that yields another
  type, `null` included). PHPStan types `get(Foo::class)` as `Foo`, any other id as `mixed`.
- `has()` is true for an id with a factory, an instantiable class, a binding chain that ends at one of them, and an
  id on a cycle of bindings (`get()` then throws). It runs the autoloader and checks no constructor parameters.
- One container serves one request at a time: fibers sharing one make each other's `set()` and `bind()` throw, and a
  second `get()` of an entry still in creation fails as a circular dependency.
- Clone between `get()` calls: the copy gets the registrations and the entries created so far. A copy made inside
  `get()` keeps the running state: its `set()`/`bind()` lock, the ids in creation, entries the original then undoes.

### ContainerException, NotFoundException

```php
public function __construct(string $message = 'Container error', int $code = 0, ?\Throwable $previous = null,
    protected ?string $debugMessage = null)
public function getDebugMessage(): ?string
```

`getDebugMessage()` returns what `getMessage()` keeps out, or `null`: for a wrapped exception
`Class in file:line: message` of it and each exception behind it, joined by ` <- `.

### HasHooks (trait)

`on(string $event, callable $callback): static` appends a callback; protected `trigger()` calls them in order.

## Configuration

| Key | Type | Default | Allowed | Refused |
|---|---|---|---|---|
| `$config` (constructor, `create()`) | `array{}` | `[]` | `[]` | any other array: `ContainerException` |

## Hooks

| Event | When | Payload |
|---|---|---|
| `resolve` | A new entry has been stored; not when an existing entry is returned | `id` (string), `instance` (mixed) |
| `error` | `get()` detects a failure, before it throws | `id` (string), `exception` (Throwable) |

- `on()` throws for other events; a subclass lists its own in `EVENTS` (`[...parent::EVENTS, 'x']`), fires `trigger()`.
- `resolve` fires for the entry a binding chain ends at, dependencies first; a hook that calls `get()` for it gets it.
- A throwing `resolve` hook: `get()` throws `Resolve hook failed for 'X'.` (the hook's exception in `getPrevious()`).
  That failure is not reported to `error`; if it escapes a factory or constructor, that outer failure is reported.
  The entry and every entry created while the hook ran are dropped and created anew by the next `get()`; what a
  destructor running during the drop throws is added to `getDebugMessage()`.
- `error` fires for each failure where it is detected, and again for each factory or constructor the failure leaves
  (nested factories report on the way up). `id` is the entry that failed (a missing dependency, not the class that
  needs it); `exception` is what a factory, constructor or default threw, otherwise the container's own. While it
  runs, further failures are not reported to it. If it throws, `get()` throws the same class with the same message,
  the original in `getPrevious()`, the hook's exception described in `getDebugMessage()`.

## Exceptions

| Class | When |
|---|---|
| `NotFoundException` | `get()` of an id `has()` is false for, also at the end of a binding chain |
| `ContainerException` | `get()`: a container to autowire, a parameter it cannot fill, a missing dependency, a cycle |
| `ContainerException` | `get()`: a wrong type; a factory, constructor, default or `resolve` hook threw |
| `ContainerException` | `set()`, `bind()`: see Registration; `on()`: unknown event |
| `ContainerException` | `__construct()`, `create()`: a config array that is not empty |

`NotFoundException` extends `ContainerException`; both implement the PSR-11 interfaces, `NotFoundException` adds
no methods. What an autoloader throws while the container loads a class passes `get()`, `has()`, `bind()` unchanged.

## Security

- `get($id)` creates any autoloadable class whose constructor it can fill and runs that constructor; `has($id)` runs
  the autoloader. Never pass user input as an id or class name: map it to a fixed list of ids.
- `getMessage()` does not carry what a wrapped exception says (it may hold a DSN or a path); that text is in
  `getDebugMessage()`, `getPrevious()` and the `error` hook's payload: log those, do not show them to users.
- ASCII control characters in an id are escaped in messages (a line break as `\x0A`); the `error` hook gets the id
  unchanged. Unicode line separators (U+2028, U+2029, U+0085) pass: write log lines with `json_encode()`.

## Testing

`composer test`, `composer analyse` (level max), `composer cs`, `composer validate --strict`; no environment variables.

## Upgrading

See [CHANGELOG.md](CHANGELOG.md).

## License

MIT

Parts of this project (refactoring, documentation, code review) were developed with AI assistance (Claude).
