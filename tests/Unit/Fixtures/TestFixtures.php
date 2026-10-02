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

class ServiceWithIntersectionNullableDefault
{
    public function __construct(public (ServiceInterface&\Countable)|null $value = null)
    {
    }
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

// ==================== Variadic Parameters ====================

class ServiceWithVariadic
{
    /** @var TestService[] */
    public array $services;

    public function __construct(TestService ...$services)
    {
        $this->services = $services;
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

// ==================== Optional Dependencies the Container Cannot Create ====================

enum Mode
{
    case Fast;
    case Safe;
}

class ServiceWithEnumDefault
{
    public function __construct(public Mode $mode = Mode::Safe)
    {
    }
}

class ServiceWithOptionalAbstract
{
    public function __construct(public ?AbstractService $service = null)
    {
    }
}

class ServiceWithOptionalBroken
{
    public function __construct(public ?ServiceWithConfig $service = null)
    {
    }
}

class ServiceWithNullableNoDefault
{
    public function __construct(public ?NonExistentInterface $dep)
    {
    }
}

class ServiceWithOptionalInterface
{
    public function __construct(public ?ServiceInterface $service = null)
    {
    }
}

class ServiceWithObjectDefault
{
    public function __construct(public LoggerInterface $logger = new FileLogger())
    {
    }
}

// ==================== Cycles Outside of Autowiring ====================

interface FirstInterface
{
}

interface SecondInterface
{
}

class NeedsLogger
{
    public function __construct(public LoggerInterface $logger)
    {
    }
}

// ==================== Constructor That Fails on Demand ====================

class ServiceFailsOnDemand
{
    public static bool $fail = false;

    public function __construct(public TestService $service)
    {
        if (self::$fail) {
            throw new \RuntimeException('Failing on demand');
        }
    }
}

// ==================== Defaults That Run Code ====================

class CountingLogger implements LoggerInterface
{
    public static int $created = 0;

    public function __construct()
    {
        self::$created++;
    }
}

class ServiceWithCountingDefault
{
    public function __construct(public LoggerInterface $logger = new CountingLogger())
    {
    }
}

class ThrowingLogger implements LoggerInterface
{
    public function __construct()
    {
        throw new \RuntimeException('Default failed intentionally');
    }
}

class ServiceWithThrowingDefault
{
    public function __construct(public LoggerInterface $logger = new ThrowingLogger())
    {
    }
}

class ServiceWithThrowingUntypedDefault
{
    public function __construct(public $logger = new ThrowingLogger())
    {
    }
}

class ServiceWithUntypedObjectDefault
{
    public function __construct(public $logger = new FileLogger())
    {
    }
}

class ServiceWithUntypedEnumDefault
{
    public function __construct(public $mode = Mode::Safe)
    {
    }
}

// ==================== A Logger That Cannot Be Built ====================

class LoggerNeedingTransport implements LoggerInterface
{
    public function __construct(public NonExistentInterface $transport)
    {
    }
}

// ==================== A Default That Relies on an Earlier Dependency ====================

class BootedService
{
    public static bool $booted = false;

    public function __construct()
    {
        self::$booted = true;
    }
}

class NeedsBootedService
{
    public function __construct()
    {
        if (!BootedService::$booted) {
            throw new \RuntimeException('Created before the dependency in front of it');
        }
    }
}

class ServiceWithDefaultAfterDependency
{
    public function __construct(public BootedService $boot, public $value = new NeedsBootedService())
    {
    }
}

// ==================== Replacement Named by Cached Metadata ====================

class ReplacementService extends TestService
{
}
