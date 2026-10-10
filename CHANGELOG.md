# Changelog

## [Unreleased]

## [2.1.0] - 2026-10-10

### Upgrading from 2.0.1

Every change a caller can see is a row of this table; read it whole, no short rule covers them. Most of them make wiring throw that was already wrong (a definition registered too late, a factory or binding of the wrong type, a container that was autowired). Some change what a call returns or throws without any wrong wiring: a parameter typed `T|false` (or `T|false|null`), `has()` for a cycle of bindings, the exception a throwing hook leaves, and the entries a failed `resolve` hook undoes.

| 2.0.1 | 2.1.0 | What to change |
|---|---|---|
| `set()` or `bind()` from a factory, a hook or a destructor while `get()` runs was accepted for ids not on the way | It throws a `ContainerException` | Register before the first `get()`; a replacement after a failed `get()` has returned |
| A `set()` factory under a class or interface name could return anything, `null` included | `get()` throws a `ContainerException` | Return an instance of the class, or register the value under a name that is no class |
| `bind()` took any implementation, both names in any spelling, and loaded no class | It loads both classes (an autoloader's exception now leaves `bind()`), and throws for an implementation that neither implements nor extends the interface, and for a class written otherwise than declared (another case, a leading `\`, the name of a `class_alias()`), as far as the class is loaded or found under that spelling | Bind a class that implements the interface, write both with `::class` of the declared class, and register a factory under the declared name |
| An id bound to a name whose entry is of another type returned that entry | `get()` throws a `ContainerException` | Bind to something that implements the interface |
| Any class that implements `ContainerInterface` (this container, a subclass, a container of another kind) was autowired: a parameter `Container $c` got a new, empty container, `get(Container::class)` returned a new one; `?ContainerInterface $c = null` got `null` | `get()` throws a `ContainerException`, unless a factory or binding is registered for the type | This container: `set(ContainerInterface::class, fn (Container $c) => $c)` (or under the class the parameter names); a container of another kind: a factory that creates it |
| An `error` hook that threw replaced the exception of `get()` | `get()` throws the exception it was about to throw (same class and message), that one in `getPrevious()`; `getDebugMessage()` has its debug message, then the hook's exception described | A hook can no longer turn container failures into exceptions of its own: catch the container's exception where `get()` is called |
| What a `resolve` hook threw left `get()` unchanged | It is wrapped in a `ContainerException` (`Resolve hook failed for 'X'.`), the hook's exception in `getPrevious()` | Catch `ContainerException` where `get()` is called and read `getPrevious()` for the hook's exception |
| `has()` was false for an id on a cycle of bindings | It is true; `get()` still throws a `ContainerException` | Code that skipped a service when `has()` was false now sees the error |
| `T\|false $x = false` (also `T\|false\|null`) always got its default: with `T` bound, and with `T` a class the container could create; `T\|false $x` without default threw `it has a union type` | The parameter is resolved like `?T`: a binding or factory for `T` is used, a concrete class `T` is autowired, one that cannot be built throws; without a default the parameter is required like `T` | If the default was meant to win, give the parameter another type (removing a binding does not help for a concrete class, which is autowired) |
| A `resolve` hook that threw undid its entry only | It also undoes every entry created while it ran; their constructors run again on the next `get()` | Nothing |
| A copy made with `clone` in a factory or a hook could register entries | It carries the lock of the running `get()`: `set()` and `bind()` on it throw | Make copies outside of `get()`; a copy made inside one is not supported |
| Messages `Cannot redefine 'X': the entry has been created or is being created.` and `Interface 'X' not found: ...` for an interface bound to itself | `... the entry has been created.` and `Interface 'X' is bound to itself: ...` | Adjust code that compares messages |

### Security

The container no longer fails open in these cases (details below): a `Container` parameter got a new, empty container past the application's hardened factories; `T|false` got `false` past a binding (a revocation list could be skipped); a class created by a throwing `resolve` hook kept the instance the hook had rejected; `bind()` and factories could hand out an object of another type under a class name.

### Changed

- `set()` and `bind()` throw a `ContainerException` while `get()` runs, for any id: from a factory, a `resolve` or `error` hook, or a destructor that runs then. They used to throw only for the entries on the way; a factory could still bind a type an entry being created had already got the default for, and that entry kept the default without notice. The message is `Cannot define 'X' while get() is running: register definitions before it, not from a factory or a hook.`
- The message for an entry that exists ends in `the entry has been created.` (was `... has been created or is being created.`).
- `has()` is true for an id on a cycle of bindings; `get()` throws a `ContainerException` for it, as before. `has()` was false (so documented since 1.1.0), and PSR-11 allows an exception other than a `NotFoundExceptionInterface` only when `has()` is true. A cycle is a known entry that is broken, not a missing one.
- A throwing `error` hook no longer replaces the exception `get()` was about to throw. `get()` throws an exception of the same class with the same message, the exception it was about to throw in `getPrevious()` (the cause of a factory or constructor behind it), and in `getDebugMessage()` that exception's debug message followed by what the hook threw. The hook's exception used to leave `get()` instead, the failure was lost, and a `NotFoundException` could come out for an id `has()` is true for, or something else for one it is false for.
- Whatever a `resolve` hook throws is wrapped in a `ContainerException` (`Resolve hook failed for 'X'.`, the hook's exception in `getPrevious()` and described in `getDebugMessage()`), as decided for PSR-11: an exception that leaves `get()` is a `ContainerExceptionInterface`, and a `NotFoundExceptionInterface` from a hook would claim that an entry `has()` is true for does not exist. The hook's exception left `get()` unchanged, its message included.
- No container is autowired: `get()` throws a `ContainerException` for a class that implements `ContainerInterface` (this container included) and has no factory, and for a constructor parameter that asks for a container nothing is registered for, optional or not. A parameter `Container $c` got a new, empty container, without the factories, bindings and hooks of the one that was asked; an optional `?ContainerInterface $c = null` got `null`. The message says how to register it: this container for a type it is an instance of (`set(ContainerInterface::class, fn (Container $c) => $c)`), a factory for a container of another kind. A container parameter whose binding fails reports the binding's failure, not a missing registration.
- A union of one class with `null` or `false` (`T|false`, `T|false|null`) is resolved like `?T`: a binding, factory or entry for the class is used, a class that exists but cannot be built throws, and without a default the parameter is required like `T`. It got its default however the class was registered (`RevocationList|false $list = false` got `false` with a binding in place), and without a default it threw `it has a union type`. Other unions are unchanged.
- `bind()` loads both classes it is given and throws a `ContainerException` when the implementation neither implements nor extends the interface, and when either is written otherwise than declared (another case, a leading `\`, the name of a `class_alias()`). It accepted all of it: `get()` of the interface returned an object of another type, a binding under another spelling was passed over by autowiring (an optional parameter got its default), and an implementation in another spelling became a second entry beside the one of its class. `bind()` can only check classes that exist (or that an autoloader finds under the spelling given): a name that does not exist (a typo) is accepted and fails at `get()`, as before. Classes that exist can no longer be bound in a cycle.
- A `set()` factory registered under a class or interface name must return an instance of it, `null` included: `get()` throws a `ContainerException` (`Error while creating service 'X': the factory returned T, not an instance of it.`), reported to the `error` hook, and nothing is created. `get()` of an id bound to a name whose entry is of another type throws as well (that entry stays, it is fine for its own name). Both returned what they got, although `get()` of a class is typed to return an instance of it.

### Fixed

- A `resolve` hook that throws undoes its entry together with every entry created while it ran. Only the entry and the bindings to it went: a class the hook had created with the entry kept the instance that never passed the hook, and after the next `get()` the container held two instances of the entry. An entry the hook created for itself is undone as well; its constructor runs again on the next `get()`.
- A destructor that runs when the container discards values (the entries a failed `resolve` hook undoes, a factory result of the wrong type) no longer replaces the exception `get()` throws: what it throws is added to that exception's debug message. A destructor that threw, or called `set()` or `get()`, while a failed hook's entry was undone made `get()` throw that exception instead of the hook's.
- `get()` checks the type of an entry it finds, too, when its id names a class or interface: a factory under a name that was no class yet returned something, the class was declared afterwards, and `get()` went on returning the value of another type.
- A constructor parameter whose type writes a bound interface in another case gets the binding also when the interface was not loaded before: `bind()` loads it. With an autoloader that finds a class under its declared name only (PSR-4 on a case-sensitive file system), the type stayed as written and the parameter got its default.
- `get()` of an interface bound to itself says so: `Interface 'X' is bound to itself: bind it to a class that implements it.` (was `... not found: no implementation is bound to it.`).

### Documentation

- README: what a lazy object's initializer does when it fails, that `(string) $e` brings back what `getMessage()` keeps out, that `zend.exception_ignore_args` is off without a `php.ini`, that `has()` runs the autoloader and is no allowlist, that Unicode line separators in an id pass unchanged (log with `json_encode()`, as the hook example now does), that a type in another case finds a class only once it is loaded.
- CHANGELOG 2.0.1: the new exception class and the type lock moved to Changed, internal changes listed.

### Internal

- PHPUnit 11 and 12 start at 11.5.50 and 12.5.8. The suite was run on both and on 13.4.1, the version in the lock.
- The private method that autowires a class is called `autowire()`, apart from the `resolve` hook. `following` is replaced by a counter of running `get()` calls; the defaults an entry gets are marked at once and dropped with the entry; undoing a failed hook cuts the entries back to a mark.
- New test files by topic: registration during `get()`, the hook rollback, error hooks that throw, container injection, union types, binding checks, destructors of discarded values.
- CI pins its actions to commit SHAs (checkout v6.1.0, setup-php 2.40.0, cache v5.1.0) and its container image to a digest.

## [2.0.1] - 2026-10-09

### Upgrading from 2.0.0

Nothing to change for code that registers everything before the first `get()` and whose hooks do not throw. Three fixes can show:

| 2.0.0 | 2.0.1 |
|---|---|
| `get()` of an abstract class, an enum or a class without public constructor (also through a binding) threw a plain `ContainerException` | It throws a `NotFoundException`, which extends `ContainerException`. Code that told the two apart by `$e::class` or `instanceof NotFoundException` sees the new class. |
| `set()` or `bind()` for a type, after an entry was created with the default value of a parameter of that type, was accepted and had no effect on that entry | It throws a `ContainerException`. Register the definition before the first `get()`. |
| A `resolve` hook that threw left the entry in place: the next `get()` returned it without running the hook | The entry is undone: the next `get()` runs the factory or constructor and the hook again. |

### Changed

- `get()` throws a `NotFoundException` for every id `has()` is false for, as PSR-11 requires: also for an abstract class, an enum, a class whose constructor is not public, and a binding that ends at one of them. It threw a plain `ContainerException` for those. A class that needs such a dependency throws a `ContainerException` with the `NotFoundException` in `getPrevious()`, so its message names the dependency, not the class at the end of the binding. A cycle of bindings still throws a `ContainerException` while `has()` is false.
- `set()` or `bind()` for a type an entry was created with the default value for throws a `ContainerException`. It was accepted, and the entry kept its default (often `null`) without notice.

### Fixed

- An entry whose `resolve` hook throws is undone, with the bindings to it that the hook asked for and the defaults it got (each type once, also when several parameters share it). It used to stay: the next `get()` returned an instance that never passed the hook, and `set()` for it threw.
- A constructor parameter whose type writes the class in another case than it is declared gets the entry, binding or factory of that class. It got an entry of its own, past the factory.
- ASCII control characters in an id are escaped in exception messages (a line break shows as `\x0A`), so a line break in an id does not start a new line; Unicode line separators pass unchanged (see 2.1.0). The `error` hook receives the id unchanged.
- Messages say why an entry cannot be created: `Class 'X' is not instantiable: it is abstract.` (also `it is an enum`, `its constructor is not public`; was `(abstract or interface)`), `Interface 'X' not found: no implementation is bound to it.`, `Cannot resolve parameter 'p' in class 'X': it has no type.` (also `a union type`, `an intersection type`).
- `composer.json` declares that the package provides `psr/container-implementation`.

### Documentation

- README: `has()`, the order of the `resolve` hook (dependencies first), PHP's own lazy objects, what `getPrevious()` and the `error` hook carry (and `zend.exception_ignore_args`), that a `resolve` hook's exception is not reported to the `error` hook, fibers sharing a container, `clone`.
- CHANGELOG: the `create()` breaks for 1.x subclasses in the upgrade table of 2.0.0, 2.0.0-beta.1, the date of 1.0.0, comparison links.

### Internal

- `composer.lock` is committed (and left out of the package archive), `composer validate --strict` runs in CI.
- CI runs every check from one job: the tests on PHP 8.5, on the PHP 8.6 pre-release (allowed to fail) and with the lowest dependencies, PHPStan and the code style. PHPUnit 12 and 13 are allowed besides 11.
- PHPStan analyses `src` and every test at level max.
- The tests are split into files by topic; tests that proved nothing are gone.

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

[Unreleased]: https://github.com/SoDaHo/php-container/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/SoDaHo/php-container/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/SoDaHo/php-container/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/SoDaHo/php-container/compare/v1.1.0...v2.0.0
[2.0.0-beta.1]: https://github.com/SoDaHo/php-container/compare/v1.1.0...v2.0.0-beta.1
[1.1.0]: https://github.com/SoDaHo/php-container/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/SoDaHo/php-container/releases/tag/v1.0.0
