# Changelog

## [Unreleased]

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
