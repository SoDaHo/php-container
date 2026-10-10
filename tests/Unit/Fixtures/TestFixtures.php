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

// Implements the interface, so that it can be bound to it (and fail there: it is abstract)
abstract class AbstractService implements ServiceInterface
{
}

interface ServiceInterface
{
}

interface FirstInterface
{
}

// A chain of bindings needs subtypes: FirstInterface -> SecondInterface -> ConcreteService. Declared before the
// classes that implement it: a class can only be declared once what it implements is
interface SecondInterface extends FirstInterface, ServiceInterface
{
}

// Implements SecondInterface, which extends FirstInterface and ServiceInterface: it can end a chain of bindings
class ConcreteService implements SecondInterface
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

// A logger itself, so that LoggerInterface can be bound to it (a cycle through a binding)
class NeedsLogger implements FirstInterface, LoggerInterface
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

// ==================== Types Written Other Than the Class Is Declared ====================

class ServiceWithLowercaseType
{
    // @phpstan-ignore class.nameCase, class.nameCase (written in another case on purpose: parameter and property)
    public function __construct(public testservice $service)
    {
    }
}

class ServiceWithOptionalLowercaseInterface
{
    // @phpstan-ignore interface.nameCase, interface.nameCase (written in another case on purpose: parameter and property)
    public function __construct(public ?serviceinterface $service = null)
    {
    }
}

class ServiceWithOptionalMissingClass
{
    // @phpstan-ignore class.notFound, class.notFound (missing on purpose: parameter and property)
    public function __construct(public ?\Missing\Thing $thing = null)
    {
    }
}

// ==================== Classes That Cannot Be Instantiated ====================

class ServiceWithPrivateConstructor
{
    private function __construct()
    {
    }
}

class ServiceNeedingAbstract
{
    public function __construct(public AbstractService $service)
    {
    }
}

// ==================== A Class That Would Have Got a Default, Had It Been Created ====================

class ServiceWithOptionalInterfaceThatThrows
{
    public function __construct(public ?ServiceInterface $service = null)
    {
        throw new \RuntimeException('Constructor failed intentionally');
    }
}

// ==================== Two Parameters of One Type That Both Get Their Default ====================

class ServiceWithTwoOptionalsOfOneType
{
    public function __construct(
        public ?ServiceInterface $first = null,
        public ?ServiceInterface $second = null,
        public ?LoggerInterface $logger = null,
    ) {
    }
}

// ==================== A Default in Front of a Dependency Whose Factory Registers ====================

class ServiceWithOptionalInterfaceThenService
{
    public function __construct(public ?ServiceInterface $service = null, public ?TestService $later = null)
    {
    }
}

// ==================== Services That Ask for a Container ====================

class ServiceNeedingContainer
{
    public function __construct(public \Sodaho\Container\Container $container)
    {
    }
}

class ServiceNeedingPsrContainer
{
    public function __construct(public \Psr\Container\ContainerInterface $container)
    {
    }
}

class ServiceWithOptionalPsrContainer
{
    public function __construct(public ?\Psr\Container\ContainerInterface $container = null)
    {
    }
}

// ==================== A Class in a Union With null or false ====================

class ServiceWithFalseUnion
{
    public function __construct(public ServiceInterface|false $service = false)
    {
    }
}

class ServiceWithFalseOrNullUnion
{
    public function __construct(public ServiceInterface|false|null $service = null)
    {
    }
}

class ServiceWithUnbuildableFalseUnion
{
    public function __construct(public ServiceWithConfig|false $service = false)
    {
    }
}

class ServiceWithFalseUnionNoDefault
{
    public function __construct(public TestService|false $service)
    {
    }
}

class ServiceWithClassOrStringUnion
{
    public function __construct(public TestService|string $value = 'default')
    {
    }
}

// ==================== Destructors That Fail or Use the Container ====================

// Its destructor throws while armed; a test arms it only around the get() it watches
class ThrowingDestructor
{
    public static bool $armed = false;

    public function __destruct()
    {
        if (self::$armed) {
            self::$armed = false;
            throw new \LogicException('Destructor failed');
        }
    }
}

// Its destructor runs what a test put in $run, once
class DestructorCallback
{
    /** @var (\Closure(): mixed)|null */
    public static ?\Closure $run = null;

    public function __destruct()
    {
        $run = self::$run;
        self::$run = null;
        if ($run !== null) {
            $run();
        }
    }
}
