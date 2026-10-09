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

class ServiceWithDependencyBeforePrimitive
{
    public function __construct(public TestService $service, public string $apiKey)
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
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $value = 'default')
    {
    }
}

class ServiceWithNoTypeNoDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
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
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $logger = new ThrowingLogger())
    {
    }
}

class ServiceWithUntypedObjectDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $logger = new FileLogger())
    {
    }
}

class ServiceWithUntypedEnumDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
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
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public BootedService $boot, public $value = new NeedsBootedService())
    {
    }
}

// ==================== A Factory That Uses the Container When It Is Destroyed ====================

class FactoryThatAsksOnDestruct
{
    public function __construct(private \Sodaho\Container\Container $container, private string $id)
    {
    }

    public function __invoke(): AlternativeService
    {
        return new AlternativeService();
    }

    public function __destruct()
    {
        $this->container->get($this->id);
    }
}

// ==================== A Container That Provides an Entry Itself ====================

class ContainerWithFallback extends \Sodaho\Container\Container
{
    public function __construct(private ServiceInterface $service)
    {
        parent::__construct();
    }

    public function get(string $id): mixed
    {
        return $id === ServiceInterface::class ? $this->service : parent::get($id);
    }

    public function has(string $id): bool
    {
        return $id === ServiceInterface::class || parent::has($id);
    }
}

// ==================== A Container With an Event of Its Own ====================

class ContainerWithBootEvent extends \Sodaho\Container\Container
{
    protected const array EVENTS = [...parent::EVENTS, 'boot'];

    public function boot(): void
    {
        $this->trigger('boot', ['container' => $this]);
    }
}
