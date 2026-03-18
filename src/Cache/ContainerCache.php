<?php

declare(strict_types=1);

namespace Sodaho\Container\Cache;

use Sodaho\Container\Exception\CacheException;

/**
 * Caches resolved dependency trees for OPcache optimization.
 *
 * Stores the "build instructions" (which dependencies each class needs)
 * so Reflection doesn't need to run on every request.
 */
class ContainerCache
{
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
        // Security: Require signature key in production (when caching is enabled)
        // This prevents RCE via tampered cache files in shared hosting environments
        if ($enabled && $signatureKey === null) {
            throw CacheException::signatureKeyRequired();
        }

        $this->cacheFile = $cacheFile;
        $this->signatureKey = $signatureKey;
        $this->enabled = $enabled;
    }

    /**
     * Save resolved dependency data to cache.
     *
     * @param array<string, array{class: class-string, dependencies: array<int, string|null>, defaults: array<int, mixed>}> $data
     *
     * @throws CacheException If writing fails
     */
    public function save(array $data): void
    {
        if (!$this->enabled) {
            return;
        }

        // Ensure cache directory exists
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
                throw CacheException::directoryNotWritable($directory);
            }
        }

        $export = var_export($data, true);
        $content = "<?php\n";

        if ($this->signatureKey !== null) {
            $signature = $this->generateSignature($export);
            $content .= "// HMAC-SHA256: {$signature}\n";
        }

        $content .= "return {$export};";

        // Atomic write (prevents partial reads)
        $tempFile = $this->cacheFile . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tempFile, $content) === false) {
            @unlink($tempFile);
            throw CacheException::writeFailed($this->cacheFile);
        }

        // Atomic move
        // @codeCoverageIgnoreStart
        if (!rename($tempFile, $this->cacheFile)) {
            @unlink($tempFile);
            throw CacheException::writeFailed($this->cacheFile);
        }
        // @codeCoverageIgnoreEnd

        // Invalidate OPcache so the new file is picked up immediately
        // @codeCoverageIgnoreStart
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->cacheFile, true);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Load dependency data from cache.
     *
     * @throws CacheException If signature validation fails
     *
     * @return array<string, array{class: class-string, dependencies: array<int, string|null>, defaults: array<int, mixed>}>|null
     */
    public function load(): ?array
    {
        if (!$this->enabled || !file_exists($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        // Validate signature if key is provided
        if ($this->signatureKey !== null) {
            if (!preg_match('/^<\?php\s*\n\/\/ HMAC-SHA256: ([a-f0-9]{64})\n/i', $content, $matches)) {
                throw CacheException::invalidSignature();
            }

            $signature = $matches[1];

            $exportStart = strpos($content, 'return ');
            if ($exportStart === false) {
                throw CacheException::invalidSignature();
            }

            $export = substr($content, $exportStart);
            $export = rtrim($export, ';');
            $export = substr($export, 7); // Remove "return "

            if (!$this->verifySignature($export, $signature)) {
                throw CacheException::invalidSignature();
            }
        }

        try {
            $data = require $this->cacheFile;
            if (!is_array($data)) {
                return null;
            }
            /** @var array<string, array{class: class-string, dependencies: array<int, string|null>, defaults: array<int, mixed>}> $data */
            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Clear the cache.
     *
     * @return bool True if cleared, false if file didn't exist
     */
    public function clear(): bool
    {
        if (file_exists($this->cacheFile)) {
            return unlink($this->cacheFile);
        }
        return false;
    }

    /**
     * Check if cache exists.
     */
    public function exists(): bool
    {
        return $this->enabled && file_exists($this->cacheFile);
    }

    private function generateSignature(string $data): string
    {
        assert($this->signatureKey !== null);
        return hash_hmac('sha256', $data, $this->signatureKey);
    }

    private function verifySignature(string $data, string $signature): bool
    {
        $expected = $this->generateSignature($data);
        return hash_equals($expected, $signature);
    }
}
