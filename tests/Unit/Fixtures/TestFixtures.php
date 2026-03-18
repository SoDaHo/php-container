<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit\Fixtures;

// ==================== Basic Services ====================

class TestService
{
}

class TestController
{
    public function __construct(public TestService $service)
    {
    }
}

class DeepController
{
    public function __construct(public TestController $controller)
    {
    }
}

// ==================== Services with Config ====================

class ServiceWithConfig
{
    public function __construct(public string $apiKey)
    {
    }
}

class ServiceWithDefaults
{
    public function __construct(
        public string $value = 'default',
        public int $number = 42,
    ) {
    }
}

// ==================== Optional/Nullable Dependencies ====================

interface NonExistentInterface
{
}

class ServiceWithOptionalDep
{
    public function __construct(public ?NonExistentInterface $optional = null)
    {
    }
}

class ServiceWithNullableDep
{
    public function __construct(public ?NonExistentInterface $dep = null)
    {
    }
}

// ==================== Type Edge Cases ====================

class ServiceWithUnionDefault
{
    public function __construct(public string|int $value = 'default')
    {
    }
}

class ServiceWithUnionNoDefault
{
    public function __construct(public string|int $value)
    {
    }
}

interface CountableService extends ServiceInterface, \Countable
{
}

class ServiceWithIntersectionNoDefault
{
    public function __construct(public ServiceInterface&\Countable $value)
    {
    }
}

class ServiceWithNoTypeDefault
{
    public function __construct(public $value = 'default')
    {
    }
}

class ServiceWithNoTypeNoDefault
{
    public function __construct(public $value)
    {
    }
}

// ==================== Abstract/Interface ====================

abstract class AbstractService
{
}

interface ServiceInterface
{
}

class ConcreteService implements ServiceInterface
{
}

class AlternativeService implements ServiceInterface
{
}

class ControllerWithInterface
{
    public function __construct(public ServiceInterface $service)
    {
    }
}

interface LoggerInterface
{
}

class FileLogger implements LoggerInterface
{
}

// ==================== Circular Dependencies ====================

class CircularA
{
    public function __construct(public CircularB $b)
    {
    }
}

class CircularB
{
    public function __construct(public CircularA $a)
    {
    }
}

class SelfDependent
{
    public function __construct(public SelfDependent $self)
    {
    }
}

// ==================== Constructor Exception ====================

class ServiceThrowsInConstructor
{
    public function __construct()
    {
        throw new \RuntimeException('Constructor failed intentionally');
    }
}
