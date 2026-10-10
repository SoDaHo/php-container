<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Feature\Fixtures;

use Sodaho\Container\Container;

// ==================== Interfaces ====================

// The logger the application binds
interface LoggerInterface
{
    public function log(string $message): void;
}

// The cache the application binds
interface CacheInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;
}

// The database the application binds
interface DatabaseInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function query(string $sql): array;
}

// ==================== Implementations ====================

// The logger bound in most scenarios
class FileLogger implements LoggerInterface
{
    public function log(string $message): void
    {
        // Would write to file in real implementation
    }
}

// A logger that drops everything: an override for tests
class NullLogger implements LoggerInterface
{
    public function log(string $message): void
    {
        // Do nothing
    }
}

// Decorates another logger
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

// A cache in memory
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

// The database bound in the bootstrap scenario
class SqliteDatabase implements DatabaseInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function query(string $sql): array
    {
        return [];
    }
}

// ==================== Services ====================

// Needs the database and the logger
class UserService
{
    public function __construct(
        public DatabaseInterface $database,
        public LoggerInterface $logger,
    ) {
    }
}

// Needs the logger
class UserController
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

// Needs the logger as well: shares it with UserController
class ProductController
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

// Needs configuration: created by a factory
class MailerService
{
    public function __construct(
        public string $host,
        public int $port,
        public LoggerInterface $logger,
    ) {
    }
}

// Needs two services and configuration: created by a factory
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

// The root of the bootstrap scenario
class Application
{
    public function __construct(
        public UserController $userController,
        public UserService $users,
        public MailerService $mailer,
    ) {
    }
}

// ==================== Service Locator Pattern ====================

// Gets the container from a factory, the way to pass it
class ServiceLocator
{
    public function __construct(public Container $container)
    {
    }
}
