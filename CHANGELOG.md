# Changelog

## [2.0.0] - Unreleased

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

- The cache and everything that configures it (`cacheFile`, `cacheSignature`, `CONTAINER_CACHE_*`, `enableCache()`, `disableCache()`, `saveCache()`, `clearCache()`, the `cacheHit` and `cacheMiss` hooks, `ContainerCache`, `CacheException`) will be removed in 2.0. It does not pay off; the README has the measurements.

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

## [1.0.0] - 2026-03-15

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
