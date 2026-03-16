<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Feature\Fixtures;

use Sodaho\Container\Container;

// ==================== Interfaces ====================

interface LoggerInterface
{
    public function log(string $message): void;
}

interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;
}

interface DatabaseInterface
{
    public function query(string $sql): array;
}

// ==================== Implementations ====================

class FileLogger implements LoggerInterface
{
    public function log(string $message): void
    {
        // Would write to file in real implementation
    }
}

class NullLogger implements LoggerInterface
{
    public function log(string $message): void
    {
        // Do nothing
    }
}

class TimestampLogger implements LoggerInterface
{
    public function __construct(public LoggerInterface $inner)
    {
    }

    public function log(string $message): void
    {
        $this->inner->log('[' . date('Y-m-d H:i:s') . '] ' . $message);
    }
}

class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}

class SqliteDatabase implements DatabaseInterface
{
    public function query(string $sql): array
    {
        return [];
    }
}

// ==================== Services ====================

class UserService
{
    public function __construct(
        public DatabaseInterface $database,
        public LoggerInterface $logger,
    ) {
    }
}

class UserController
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

class ProductController
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

class MailerService
{
    public function __construct(
        public string $host,
        public int $port,
        public LoggerInterface $logger,
    ) {
    }
}

class ComplexService
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public LoggerInterface $logger,
        public CacheInterface $cache,
        public string $apiKey,
        public array $options,
    ) {
    }
}

// ==================== Application ====================

class Application
{
    public function __construct(
        public UserController $userController,
        public ProductController $productController,
    ) {
    }
}

// ==================== Service Locator Pattern ====================

class ServiceLocator
{
    public function __construct(public Container $container)
    {
    }
}
