<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Sodaho\Container\Exception\CacheException;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * Unit tests for Exception classes - PSR-11 compliance.
 */
class ExceptionTest extends TestCase
{
    // ==================== PSR-11 Compliance ====================

    public function testContainerExceptionImplementsPsrInterface(): void
    {
        $exception = new ContainerException('Test');

        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    public function testNotFoundExceptionImplementsPsrInterface(): void
    {
        $exception = new NotFoundException('Test');

        $this->assertInstanceOf(NotFoundExceptionInterface::class, $exception);
    }

    public function testCacheExceptionImplementsPsrInterface(): void
    {
        $exception = new CacheException('Test');

        $this->assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    // ==================== CacheException Factory Methods ====================

    public function testCacheExceptionDirectoryNotWritable(): void
    {
        $exception = CacheException::directoryNotWritable('/some/path');

        $this->assertStringContainsString('/some/path', $exception->getMessage());
        $this->assertStringContainsString('not writable', $exception->getMessage());
    }

    public function testCacheExceptionWriteFailed(): void
    {
        $exception = CacheException::writeFailed('/some/file.php');

        $this->assertStringContainsString('/some/file.php', $exception->getMessage());
        $this->assertStringContainsString('Failed to write', $exception->getMessage());
    }

    public function testCacheExceptionInvalidSignature(): void
    {
        $exception = CacheException::invalidSignature();

        $this->assertStringContainsString('signature', $exception->getMessage());
        $this->assertNotNull($exception->getDebugMessage());
        $this->assertStringContainsString('tampered', $exception->getDebugMessage());
    }

    public function testCacheExceptionSignatureKeyRequired(): void
    {
        $exception = CacheException::signatureKeyRequired();

        $this->assertStringContainsString('signature key is required', $exception->getMessage());
        $this->assertNotNull($exception->getDebugMessage());
        $this->assertStringContainsString('RCE', $exception->getDebugMessage());
        $this->assertStringContainsString('CONTAINER_CACHE_KEY', $exception->getDebugMessage());
    }

    // ==================== Debug Message ====================

    public function testContainerExceptionDebugMessage(): void
    {
        $exception = new ContainerException('User message', 0, null, 'Debug info for logging');

        $this->assertEquals('User message', $exception->getMessage());
        $this->assertEquals('Debug info for logging', $exception->getDebugMessage());
    }

    public function testContainerExceptionDebugMessageNullByDefault(): void
    {
        $exception = new ContainerException('User message');

        $this->assertNull($exception->getDebugMessage());
    }

    // ==================== Exception Chaining ====================

    public function testContainerExceptionSupportsPrevious(): void
    {
        $previous = new \RuntimeException('Original error');
        $exception = new ContainerException('Wrapped error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testNotFoundExceptionSupportsPrevious(): void
    {
        $previous = new \RuntimeException('Original error');
        $exception = new NotFoundException('Wrapped error', 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
