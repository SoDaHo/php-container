# Changelog

## [Unreleased]

### Security

- The cache file is no longer executed. It is a signed data file now; the HMAC covers every byte that is used. Previously, code placed between the signature line and `return` passed verification and was run. Cache files written by earlier versions are ignored and rewritten.
- The signature is bound to this library, so a file signed with the same key for another library does not verify.
- An empty signature key is rejected like a missing one.

### Deprecated

- The cache and everything that configures it (`cacheFile`, `cacheSignature`, `CONTAINER_CACHE_*`, `enableCache()`, `disableCache()`, `saveCache()`, `clearCache()`, the `cacheHit` and `cacheMiss` hooks, `ContainerCache`, `CacheException`) will be removed in 2.0. It does not pay off; the README has the measurements.

### Changed

- Upgrading with an existing cache file: the 1.0 file counts as a miss and `saveCache()` replaces it, so its directory has to be writable, or the file deleted beforehand. 1.0.x cannot read the new format: delete the file before rolling back, and do not let both versions share one file.
- `'cacheFile' => null` in the config disables caching and is no longer replaced by `CONTAINER_CACHE_FILE`.
- The `error` hook also fires for failures the container detects itself (entry not found, class not instantiable, unresolvable parameter, circular dependency, invalid cache signature), whether or not the caller catches them. While an `error` hook runs, further failures are not reported to any `error` hook.
- `has()` follows bindings and returns `false` when the bound class does not exist or is not instantiable, or when the bindings form a cycle.
- An exception thrown by a `resolve` hook is no longer reported as a failed instantiation.
- `get()` returns the requested class for PHPStan when called with a class name. A type check on the result is then reported as always true.
- `ContainerCache` stores `null`, scalars, enum cases and arrays of those; `save()` throws for other objects. Entries written by the container have an additional `optional` key; a default missing from an entry is read from the class instead of being passed as `null`. A class with such an object as default of a parameter whose type is not a single class or interface is no longer cached.

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
