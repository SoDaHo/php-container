<?php

declare(strict_types=1);

namespace Sodaho\Container\Exception;

/**
 * Exception for cache-related errors.
 */
class CacheException extends ContainerException
{
    /**
     * Cache directory is not writable.
     */
    public static function directoryNotWritable(string $directory): self
    {
        return new self(
            sprintf('Cache directory is not writable: %s', $directory),
            0,
            null,
            'Ensure the directory exists and has write permissions.'
        );
    }

    /**
     * Failed to write cache file.
     */
    public static function writeFailed(string $file): self
    {
        return new self(
            sprintf('Failed to write cache file: %s', $file),
            0,
            null,
            'Check file permissions and disk space.'
        );
    }

    /**
     * Cache signature is invalid (tampered or corrupted).
     */
    public static function invalidSignature(): self
    {
        return new self(
            'Cache file signature is invalid',
            0,
            null,
            'The cache file may have been tampered with or the signature key has changed.'
        );
    }

    /**
     * Cache signature key is required but not provided.
     */
    public static function signatureKeyRequired(): self
    {
        return new self(
            'Cache signature key is required when caching is enabled',
            0,
            null,
            'Provide a signature key via enableCache($file, $key) or set CONTAINER_CACHE_KEY environment variable. '
            . 'This prevents RCE attacks via tampered cache files.'
        );
    }
}
