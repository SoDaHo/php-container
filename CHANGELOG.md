# Changelog

## [Unreleased]

### Changed

- `set()` and `bind()` throw a `ContainerException` while `get()` runs, for any id: from a factory, a `resolve` or `error` hook, or a destructor that runs then. They used to throw only for the entries on the way; a factory could still bind a type an entry being created had already got the default for, and that entry kept the default without notice. The message is `Cannot define 'X' while get() is running: register definitions before it, not from a factory or a hook.`
- The message for an entry that exists ends in `the entry has been created.` (was `... has been created or is being created.`).
- `has()` is true for an id on a cycle of bindings; `get()` throws a `ContainerException` for it, as before. `has()` was false (so documented since 1.1.0), and PSR-11 allows an exception other than a `NotFoundExceptionInterface` only when `has()` is true. A cycle is a known entry that is broken, not a missing one.
- A throwing `error` hook no longer replaces the exception `get()` was about to throw. `get()` throws an exception of the same class with the same message, the exception it was about to throw in `getPrevious()` (the cause of a factory or constructor behind it), and what the hook threw in `getDebugMessage()`. The hook's exception used to leave `get()` instead, the failure was lost, and a `NotFoundException` could come out for an id `has()` is true for, or something else for one it is false for.
- A `NotFoundExceptionInterface` thrown by a `resolve` hook is wrapped in a `ContainerException` (`Resolve hook failed for 'X'.`, the hook's exception in `getPrevious()`): `has()` is true for the entry, and PSR-11 keeps `NotFoundExceptionInterface` for ids `has()` is false for. Anything else a `resolve` hook throws still leaves `get()` unchanged.
- No container is autowired: `get()` throws a `ContainerException` for a class that implements `ContainerInterface` (this container included), and for a constructor parameter that asks for a container nothing is registered for, optional or not. A parameter `Container $c` got a new, empty container, without the factories, bindings and hooks of the one that was asked; an optional `?ContainerInterface $c = null` got `null`. The message says how to register it: `set(ContainerInterface::class, fn (Container $c) => $c)`.
- A union of one class with `null` or `false` (`T|false`, `T|false|null`) is resolved like `?T`: a binding, factory or entry for the class is used, a class that exists but cannot be built throws, and without a default the parameter is required like `T`. It got its default however the class was registered (`RevocationList|false $list = false` got `false` with a binding in place), and without a default it threw `it has a union type`. Other unions are unchanged.
- `bind()` throws a `ContainerException` when the implementation neither implements nor extends the interface, and when the interface is written otherwise than declared (another case, a leading `\`). It accepted both: `get()` of the interface returned an object of another type, and a binding under another spelling was passed over by autowiring (an optional parameter got its default). `bind()` can only check classes that exist: a name that does not exist (a typo) is accepted and fails at `get()`, as before. Classes that exist can no longer be bound in a cycle.
- A `set()` factory registered under a class or interface name must return an instance of it, `null` included: `get()` throws a `ContainerException` (`Error while creating service 'X': the factory returned T, not an instance of it.`), reported to the `error` hook, and nothing is created. So does `get()` of an id bound to a name whose entry is of another type. Both returned what they got, although `get()` of a class is typed to return an instance of it.

### Fixed

- A `resolve` hook that throws undoes its entry together with every entry created while it ran. Only the entry and the bindings to it went: a class the hook had created with the entry kept the instance that never passed the hook, and after the next `get()` the container held two instances of the entry. An entry the hook created for itself is undone as well; its constructor runs again on the next `get()`.
- `bind()` stores the implementation under its declared name. An implementation written in another case became an entry of its own, a second instance beside the one `get()` of the class created.
- A constructor parameter whose type writes a bound interface in another case gets the binding also when the interface was not loaded before: `bind()` loads it. With an autoloader that finds a class under its declared name only (PSR-4 on a case-sensitive file system), the type stayed as written and the parameter got its default.
- `get()` of an interface bound to itself says so: `Interface 'X' is bound to itself: bind it to a class that implements it.` (was `... not found: no implementation is bound to it.`).

## [2.0.1] - 2026-10-09

### Upgrading from 2.0.0

Nothing to change for code that registers everything before the first `get()` and whose hooks do not throw. Three fixes can show:

| 2.0.0 | 2.0.1 |
|---|---|
| `get()` of an abstract class, an enum or a class without public constructor (also through a binding) threw a plain `ContainerException` | It throws a `NotFoundException`, which extends `ContainerException`. Code that told the two apart by `$e::class` or `instanceof NotFoundException` sees the new class. |
| `set()` or `bind()` for a type, after an entry was created with the default value of a parameter of that type, was accepted and had no effect on that entry | It throws a `ContainerException`. Register the definition before the first `get()`. |
| A `resolve` hook that threw left the entry in place: the next `get()` returned it without running the hook | The entry is undone: the next `get()` runs the factory or constructor and the hook again. |

### Fixed

- `get()` throws a `NotFoundException` for every id `has()` is false for, as PSR-11 requires: also for an abstract class, an enum, a class whose constructor is not public, and a binding that ends at one of them. A class that needs such a dependency throws a `ContainerException` with the `NotFoundException` in `getPrevious()`, so its message names the dependency, not the class at the end of the binding. A cycle of bindings still throws a `ContainerException` while `has()` is false.
- `set()` or `bind()` for a type an entry was created with the default value for throws a `ContainerException`. It was accepted, and the entry kept its default (often `null`) without notice.
- An entry whose `resolve` hook throws is undone, with the bindings to it that the hook asked for and the defaults it got (each type once, also when several parameters share it). It used to stay: the next `get()` returned an instance that never passed the hook, and `set()` for it threw.
- A constructor parameter whose type writes the class in another case than it is declared gets the entry, binding or factory of that class. It got an entry of its own, past the factory.
- Control characters in an id are escaped in exception messages (a line break shows as `\x0A`), so an id cannot add a line to a log. The `error` hook receives the id unchanged.
- Messages say why an entry cannot be created: `Class 'X' is not instantiable: it is abstract.` (also `it is an enum`, `its constructor is not public`; was `(abstract or interface)`), `Interface 'X' not found: no implementation is bound to it.`, `Cannot resolve parameter 'p' in class 'X': it has no type.` (also `a union type`, `an intersection type`).
- `composer.json` declares that the package provides `psr/container-implementation`.

### Documentation

- README: `has()`, the order of the `resolve` hook (dependencies first), PHP's own lazy objects, what `getPrevious()` and the `error` hook carry (and `zend.exception_ignore_args`), that a `resolve` hook's exception is not reported to the `error` hook, fibers sharing a container, `clone`.
- CHANGELOG: the `create()` breaks for 1.x subclasses in the upgrade table of 2.0.0, 2.0.0-beta.1, the date of 1.0.0, comparison links.

## [2.0.0] - 2026-10-03

### Upgrading from 1.x

The table starts at 1.1.0. Coming from 1.0.x, the changes of 1.1.0 (below) apply as well. The one most likely to show: the `error` hook also fires for failures the container detects itself (entry not found, class not instantiable, unresolvable parameter, circular dependency) and for a default value that throws, so a hook that logs writes more lines.

| 1.x | 2.0 |
|---|---|
| `new Container(['debug' => $debug])`, `Container::create([...])` | `new Container()`. There are no options; a config array that is not empty throws. If your own code validated the value while reading it (an invalid `APP_DEBUG` stopped the application at startup), keep that check; only the argument goes. |
| `'cacheFile'`, `'cacheSignature'`, `CONTAINER_CACHE_FILE`, `CONTAINER_CACHE_KEY` | Remove them and delete the cache file. |
| `enableCache()`, `disableCache()`, `saveCache()`, `clearCache()` | Remove the calls. |
| `setDebug()`, `APP_DEBUG`, `APP_ENV` | Remove the call. Debug mode did nothing but switch the cache off; the container reads no environment variables. |
| `on('cacheHit', ...)`, `on('cacheMiss', ...)` | Remove them. `on()` throws for an event that does not exist. |
| `catch (CacheException $e)`, `new ContainerCache(...)`, `ContainerCache::isCacheable()` | No replacement. Remove the code. |
| `$e->getMessage()` to read what a factory or constructor said | `$e->getDebugMessage()`, or `$e->getPrevious()->getMessage()` |
| `set()` or `bind()` for an entry that has been created or is just being created (was accepted, and ignored once the entry existed) | It throws. Register before the first `get()` that reaches the id, directly, through a binding or as a dependency; register a replacement for an entry that failed after that `get()` has returned. |
| `set($id, ...)` followed by `bind($id, ...)` (the definition won) | The binding wins: the last registration counts. Remove the one you do not want. |
| An optional parameter whose type is bound to a class that is missing or not instantiable, or through a cycle of bindings (got its default) | It throws. Correct the binding or remove it. |
| A subclass that fires its own events with `trigger()` | List them: `protected const array EVENTS = [...parent::EVENTS, 'boot'];`. A constant `EVENTS` the subclass already has needs another name. |
| A subclass that overrides `create()` with the return type `self` | PHP stops with a fatal error: the declaration must be compatible with `create(array $config = []): static`. Declare `static`. |
| `create()` called on a subclass (returned a plain `Container`) | It returns an instance of the subclass, created with `new static([])`. A subclass whose constructor takes other arguments gets a `TypeError`: create it with `new`, or override `create()`. |
| PHP 8.2, 8.3, 8.4 | PHP 8.5 |

### Removed

- The cache: `ContainerCache`, `CacheException`, `enableCache()`, `disableCache()`, `saveCache()`, `clearCache()`, the `cacheHit` and `cacheMiss` hooks, the options `cacheFile` and `cacheSignature`, the variables `CONTAINER_CACHE_FILE` and `CONTAINER_CACHE_KEY`. It cost more than the Reflection it saved.
- Debug mode: the `debug` option, `setDebug()` and the detection through `APP_DEBUG` and `APP_ENV`.
- Support for PHP 8.2, 8.3 and 8.4.

### Changed

- When a factory or constructor throws, `getMessage()` names the entry and no longer appends the message of that exception. `getDebugMessage()` has it: `Class in file:line: message` for the wrapped exception and every exception behind it.
- `set()` and `bind()` throw a `ContainerException` for an entry that was already created or is just being created.
- `on()` throws a `ContainerException` for an unknown event.
- The constructor and `create()` throw a `ContainerException` for a config array that is not empty.
- `create()` called on a subclass returns an instance of that subclass.
- The last `set()` or `bind()` for an id wins. A `bind()` after a `set()` for the same id used to have no effect.
- An optional parameter whose type has a binding gets what the binding resolves to. A binding that cannot be resolved (class missing or not instantiable, cycle) throws; it used to fall back to the default.

### Fixed

- A `NotFoundException` thrown by a hook while a class was being autowired was reported as an unresolvable dependency of that class, although the dependency exists.

## [2.0.0-beta.1] - 2026-10-02

Pre-release of 2.0.0 with the same code. 2.0.0 completed this file and the CI workflow.

## [1.1.0] - 2026-10-02

### Upgrading

Only if you use the cache:

- Delete the cache file when you update. A file written by 1.0.x is never executed again: it counts as a miss and `saveCache()` replaces it, which throws a `CacheException` if the directory is read-only. 1.0.x cannot read the new format, so delete the file before rolling back as well, and do not let both versions share one file.
- The cache file holds data only: `null`, scalars, enum cases and arrays of those. `ContainerCache::save()` throws for other objects, and a class with such an object as default of a parameter whose type is not a single class or interface is no longer cached.

### Security

- The cache file is no longer executed. It is a signed data file now; the HMAC covers every byte that is used. Previously, code placed between the signature line and `return` passed verification and was run. Cache files written by earlier versions are ignored and rewritten.
- The signature is bound to this library, so a file signed with the same key for another library does not verify.
- An empty signature key is rejected like a missing one.

### Deprecated

- The cache and everything that configures it (`cacheFile`, `cacheSignature`, `CONTAINER_CACHE_*`, `enableCache()`, `disableCache()`, `saveCache()`, `clearCache()`, the `cacheHit` and `cacheMiss` hooks, `ContainerCache`, `CacheException`) will be removed in 2.0. It does not pay off; the README of 1.1.0 has the measurements.

### Changed

- `'cacheFile' => null` in the config disables caching and is no longer replaced by `CONTAINER_CACHE_FILE`.
- The `error` hook also fires for failures the container detects itself (entry not found, class not instantiable, unresolvable parameter, circular dependency, invalid cache signature), whether or not the caller catches them. While an `error` hook runs, further failures are not reported to any `error` hook.
- `has()` follows bindings and returns `false` when the bound class does not exist or is not instantiable, or when the bindings form a cycle.
- An exception thrown by a `resolve` hook is no longer reported as a failed instantiation.
- `get()` returns the requested class for PHPStan when called with a class name. A type check on the result is then reported as always true.
- Cache entries written by the container have an additional `optional` key; a default missing from an entry is read from the class instead of being passed as `null`.

### Fixed

- Cycles through `bind()` or `set()` (also with a warm cache) ended in a stack overflow, and a factory re-entered through a hook ran twice; they throw `ContainerException` now. Binding a class to itself autowires it.
- Resolution from a warm cache behaves like cold resolution: constructor exceptions are wrapped and reported, bindings added or removed after caching are honoured. Debug mode no longer uses metadata that was loaded before it was switched on.
- A second `get()` of a class whose constructor threw leaked the raw exception.
- A constructor default that is an object disabled the cache for all classes and made every request rewrite it.
- A default that throws when evaluated (`new` in an initializer) is wrapped and reported like a failing constructor.
- `enableCache($file)` without a key discarded the configured key. Called after classes were resolved, it lost their metadata if the file already existed.
- An empty `CONTAINER_CACHE_FILE` or `CONTAINER_CACHE_KEY` counted as set. Empty values are skipped now, so an empty `$_ENV` entry no longer hides `getenv()`.
- `saveCache()` emitted a PHP warning when several first requests created the cache directory at once or when writing failed, `clearCache()` when the file could not be deleted.

### Added

- `disableCache()`.
- An optional parameter falls back to its default when its type is an abstract class or an enum, as it already did for an unbound interface.
- `getDebugMessage()` names the origin of a wrapped factory or constructor exception.

## [1.0.0] - 2026-03-19

- PSR-11 compliant dependency injection container
- Constructor autowiring via Reflection
- Interface-to-implementation binding (`bind()`)
- Manual service factories (`set()`)
- Singleton behavior for all resolved services
- Circular dependency detection
- Cache system for Reflection metadata (OPcache-optimized)
- HMAC-SHA256 cache signature verification (RCE prevention)
- Atomic cache file writes
- Debug mode with auto-detection via `APP_DEBUG` / `APP_ENV`
- Environment variable configuration (`$_ENV` > `getenv()` fallback)
- Event hooks (`resolve`, `error`, `cacheHit`, `cacheMiss`)
- Dual exception messages (user-facing + debug)

[Unreleased]: https://github.com/SoDaHo/php-container/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/SoDaHo/php-container/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/SoDaHo/php-container/compare/v1.1.0...v2.0.0
[2.0.0-beta.1]: https://github.com/SoDaHo/php-container/compare/v1.1.0...v2.0.0-beta.1
[1.1.0]: https://github.com/SoDaHo/php-container/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SoDaHo/php-container/releases/tag/v1.0.0
