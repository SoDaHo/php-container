<?php

declare(strict_types=1);

namespace Sodaho\Container\Cache;

use Sodaho\Container\Exception\CacheException;

/**
 * Caches constructor signatures so Reflection doesn't need to run on every request.
 *
 * The file holds data only and is never executed: a guard line, a signature line and the
 * serialized metadata. The HMAC covers every byte after the signature line, and exactly
 * the verified bytes are decoded. Same layout as the route cache of sodaho/php-router.
 *
 * @phpstan-type ClassMeta array{class: class-string, dependencies: array<int, string|null>, defaults: array<int, mixed>, optional: array<int, true>}
 */
class ContainerCache
{
    /** If the file is ever run as PHP (served from a web root), compiling and execution end here. */
    private const GUARD = "<?php __halt_compiler(); ?>\n";

    private const SIGNATURE_PREFIX = 'HMAC-SHA256: ';

    /** Signed along with the payload, so a file signed for another library with the same key does not verify. */
    private const CONTEXT = "sodaho/container cache v2\n";

    /** Files written by 1.0.x were executable PHP. They are never run, only replaced. */
    private const LEGACY_HEADER = "<?php\n// HMAC-SHA256: ";

    private string $cacheFile;
    private ?string $signatureKey;
    private bool $enabled;

    /**
     * Create a new ContainerCache instance.
     *
     * @param string $cacheFile Path to cache file
     * @param string|null $signatureKey HMAC key for integrity verification (required when enabled)
     * @param bool $enabled Whether caching is enabled
     *
     * @throws CacheException If enabled but no signature key provided (security requirement)
     */
    public function __construct(string $cacheFile, ?string $signatureKey = null, bool $enabled = true)
    {
        // Security: whoever can write the cache file chooses which classes get instantiated
        // with which arguments. Without a key (or with an empty one) anyone can forge it.
        if ($enabled && ($signatureKey === null || $signatureKey === '')) {
            throw CacheException::signatureKeyRequired();
        }

        $this->cacheFile = $cacheFile;
        $this->signatureKey = $signatureKey;
        $this->enabled = $enabled;
    }

    /**
     * Whether a value survives the data-only file: null, scalars, enum cases and arrays of those.
     * Other objects are not created when the file is loaded, so they are never stored.
     */
    public static function isCacheable(mixed $value): bool
    {
        if (!is_array($value)) {
            return $value === null || is_scalar($value) || $value instanceof \UnitEnum;
        }

        foreach ($value as $item) {
            if (!self::isCacheable($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save class metadata to cache.
     *
     * @param array<string, ClassMeta> $data
     *
     * @throws CacheException If writing fails or the data is not cacheable
     */
    public function save(array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        if (!self::isCacheable($data)) {
            throw CacheException::notCacheable();
        }

        // Ensure cache directory exists (concurrent first requests race here: the loser's mkdir fails)
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw CacheException::directoryNotWritable($directory);
        }

        $payload = serialize($data);
        $content = self::GUARD . self::SIGNATURE_PREFIX . $this->sign($payload) . "\n" . $payload;

        // Atomic write (prevents partial reads)
        $tempFile = $this->cacheFile . '.tmp.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            throw CacheException::writeFailed($this->cacheFile);
        }

        // Atomic move
        // @codeCoverageIgnoreStart
        if (!@rename($tempFile, $this->cacheFile)) {
            @unlink($tempFile);
            throw CacheException::writeFailed($this->cacheFile);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Load class metadata from cache.
     *
     * @throws CacheException If signature validation fails
     *
     * @return array<string, ClassMeta>|null Null if there is nothing usable (no file, or a 1.0.x file)
     */
    public function load(): ?array
    {
        if (!$this->enabled || !is_file($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        if (str_starts_with($content, self::LEGACY_HEADER)) {
            return null;
        }

        $header = self::GUARD . self::SIGNATURE_PREFIX;
        $payloadStart = strlen($header) + 64 + 1;
        if (!str_starts_with($content, $header) || ($content[$payloadStart - 1] ?? '') !== "\n") {
            throw CacheException::invalidSignature();
        }

        $payload = substr($content, $payloadStart);
        if (!hash_equals($this->sign($payload), substr($content, strlen($header), 64))) {
            throw CacheException::invalidSignature();
        }

        // Verified bytes only. No classes are instantiated (enum cases are the one kind of object PHP
        // restores here); a payload that cannot be decoded, e.g. an enum case that no longer exists, is a miss.
        $data = @unserialize($payload, ['allowed_classes' => false]);
        if (!is_array($data)) {
            return null;
        }

        /** @var array<string, ClassMeta> $data */
        return $data;
    }

    /**
     * Clear the cache.
     *
     * @return bool True if a file was deleted, false if there was none or it could not be deleted
     */
    public function clear(): bool
    {
        return file_exists($this->cacheFile) && @unlink($this->cacheFile);
    }

    /**
     * Check if cache exists.
     */
    public function exists(): bool
    {
        return $this->enabled && file_exists($this->cacheFile);
    }

    private function sign(string $payload): string
    {
        assert($this->signatureKey !== null);
        return hash_hmac('sha256', self::CONTEXT . $payload, $this->signatureKey);
    }
}
