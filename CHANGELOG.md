# Changelog

## [2.1.0] - 2026-10-10

### Changed
- `set()` and `bind()` throw while any `get()` runs, for any id; before, only for the ids on the way.
- `get()` throws when a factory or binding under a class or interface name yields no instance of it (`null` too).
- `bind()` loads both classes and throws for an implementation that is no subtype or a name not written as declared.
- The first `get()` of a factory's id runs the autoloader for that name (type check); its exceptions pass unchanged.
- No container is autowired: a `ContainerInterface` class or parameter without registration throws, optional or not.
- `T|false` and `T|false|null` parameters are resolved like `?T`; they got their default, or threw without one.
- `has()` is true for an id on a cycle of bindings; `get()` throws a `ContainerException` for it, as before.
- A throwing `resolve` hook makes `get()` throw `Resolve hook failed for 'X'.`; its exception passed unchanged.
- A throwing `error` hook keeps the failure: same class and message, the failure in `getPrevious()`.

### Fixed
- A throwing `resolve` hook undoes every entry created while it ran; entries holding its instance stayed.
- What a destructor throws while the container discards a value goes to `getDebugMessage()`; it replaced the failure.
- `get()` checks an existing entry once its class is loaded; a type in another case finds a bound, unloaded interface.

## [2.0.1] - 2026-10-09

### Changed
- `get()` throws `NotFoundException` for every id `has()` is false for (abstract class, enum, non-public constructor).
- `set()` or `bind()` for a type an entry got its default for throws; it was accepted without effect.

### Fixed
- A throwing `resolve` hook undoes its entry; the next `get()` returned it without running the hook.
- A parameter type written in another case than declared gets the class's entry, binding or factory.
- ASCII control characters in ids are escaped in messages; `composer.json` provides `psr/container-implementation`.

## [2.0.0] - 2026-10-03

### Removed
- The cache and debug mode with their methods, hooks, options and environment variables; PHP 8.2 to 8.4.

### Changed
- `getMessage()` no longer contains the message of a wrapped exception; `getDebugMessage()` has it.
- `ContainerException` for `set()`/`bind()` of a created entry, `on()` of an unknown event, a non-empty config array.
- `create()` on a subclass returns the subclass; the last registration wins; an unresolvable optional binding throws.

### Upgrading from 1.x
- `new Container()` without options; remove the cache and debug calls, options, variables and the cache file.
- Read wrapped messages from `getDebugMessage()`; register before the first `get()`; `create()` overrides: `static`.

Older versions: see the git tags.

[2.1.0]: https://github.com/SoDaHo/php-container/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/SoDaHo/php-container/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/SoDaHo/php-container/compare/v1.1.0...v2.0.0
