<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit\Fixtures;

// ==================== Basic Services ====================

// A class without dependencies
class TestService
{
}

// Needs one class: the simplest dependency
class TestController
{
    public function __construct(public TestService $service)
    {
    }
}

// Needs a class that needs a class: two levels of dependencies
class DeepController
{
    public function __construct(public TestController $controller)
    {
    }
}

// ==================== Services with Config ====================

// Needs a string, which the container cannot provide
class ServiceWithConfig
{
    public function __construct(public string $apiKey)
    {
    }
}

// A class dependency in front of a string the container cannot provide
class ServiceWithDependencyBeforePrimitive
{
    public function __construct(public TestService $service, public string $apiKey)
    {
    }
}

// Primitive parameters with defaults
class ServiceWithDefaults
{
    public function __construct(
        public string $value = 'default',
        public int $number = 42,
    ) {
    }
}

// ==================== Optional/Nullable Dependencies ====================

// An interface nothing implements
interface NonExistentInterface
{
}

// An optional dependency on an interface nothing implements
class ServiceWithOptionalDep
{
    public function __construct(public ?NonExistentInterface $optional = null)
    {
    }
}

// ==================== Type Edge Cases ====================

// A union of primitives with a default
class ServiceWithUnionDefault
{
    public function __construct(public string|int $value = 'default')
    {
    }
}

// A union of primitives without a default
class ServiceWithUnionNoDefault
{
    public function __construct(public string|int $value)
    {
    }
}

// An interface that extends two: the target of an intersection type
interface CountableService extends ServiceInterface, \Countable
{
}

// An intersection type with null and a default
class ServiceWithIntersectionNullableDefault
{
    public function __construct(public (ServiceInterface&\Countable)|null $value = null)
    {
    }
}

// An intersection type without a default
class ServiceWithIntersectionNoDefault
{
    public function __construct(public ServiceInterface&\Countable $value)
    {
    }
}

// A parameter without a type, with a default
class ServiceWithNoTypeDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $value = 'default')
    {
    }
}

// A parameter without a type and without a default
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

// The interface most bindings in the tests are made for
interface ServiceInterface
{
}

// The start of a chain of bindings
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

// A second implementation of ServiceInterface
class AlternativeService implements ServiceInterface
{
}

// Needs ServiceInterface: a dependency only a binding or factory can fill
class ControllerWithInterface
{
    public function __construct(public ServiceInterface $service)
    {
    }
}

// A second interface for bindings
interface LoggerInterface
{
}

// Implements LoggerInterface
class FileLogger implements LoggerInterface
{
}

// ==================== Circular Dependencies ====================

// Needs CircularB, which needs it: a cycle through constructors
class CircularA
{
    public function __construct(public CircularB $b)
    {
    }
}

// Needs CircularA, which needs it: a cycle through constructors
class CircularB
{
    public function __construct(public CircularA $a)
    {
    }
}

// Needs itself
class SelfDependent
{
    public function __construct(public SelfDependent $self)
    {
    }
}

// ==================== Variadic Parameters ====================

// A variadic parameter, which is not autowired
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

// Its constructor throws
class ServiceThrowsInConstructor
{
    public function __construct()
    {
        throw new \RuntimeException('Constructor failed intentionally');
    }
}

// ==================== Optional Dependencies the Container Cannot Create ====================

// An enum: a type the container cannot create
enum Mode
{
    case Fast;
    case Safe;
}

// An enum parameter with a default
class ServiceWithEnumDefault
{
    public function __construct(public Mode $mode = Mode::Safe)
    {
    }
}

// An optional dependency on an abstract class
class ServiceWithOptionalAbstract
{
    public function __construct(public ?AbstractService $service = null)
    {
    }
}

// An optional dependency on a class that exists but cannot be built
class ServiceWithOptionalBroken
{
    public function __construct(public ?ServiceWithConfig $service = null)
    {
    }
}

// A nullable dependency without a default: required
class ServiceWithNullableNoDefault
{
    public function __construct(public ?NonExistentInterface $dep)
    {
    }
}

// An optional dependency on ServiceInterface
class ServiceWithOptionalInterface
{
    public function __construct(public ?ServiceInterface $service = null)
    {
    }
}

// An object as default (new in initializer)
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

// Counts how often it is created
class CountingLogger implements LoggerInterface
{
    public static int $created = 0;

    public function __construct()
    {
        self::$created++;
    }
}

// Its default is a CountingLogger: shows whether the default was evaluated
class ServiceWithCountingDefault
{
    public function __construct(public LoggerInterface $logger = new CountingLogger())
    {
    }
}

// Its constructor throws: a default that fails
class ThrowingLogger implements LoggerInterface
{
    public function __construct()
    {
        throw new \RuntimeException('Default failed intentionally');
    }
}

// An optional dependency whose default throws
class ServiceWithThrowingDefault
{
    public function __construct(public LoggerInterface $logger = new ThrowingLogger())
    {
    }
}

// A parameter without a type whose default throws
class ServiceWithThrowingUntypedDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $logger = new ThrowingLogger())
    {
    }
}

// A parameter without a type whose default is an object
class ServiceWithUntypedObjectDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $logger = new FileLogger())
    {
    }
}

// A parameter without a type whose default is an enum case
class ServiceWithUntypedEnumDefault
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public $mode = Mode::Safe)
    {
    }
}

// ==================== A Logger That Cannot Be Built ====================

// A logger that needs an interface nothing implements: a binding that cannot be built
class LoggerNeedingTransport implements LoggerInterface
{
    public function __construct(public NonExistentInterface $transport)
    {
    }
}

// ==================== A Default That Relies on an Earlier Dependency ====================

// Records that it was created
class BootedService
{
    public static bool $booted = false;

    public function __construct()
    {
        self::$booted = true;
    }
}

// Throws unless BootedService was created before it
class NeedsBootedService
{
    public function __construct()
    {
        if (!BootedService::$booted) {
            throw new \RuntimeException('Created before the dependency in front of it');
        }
    }
}

// A default that relies on the dependency in front of it
class ServiceWithDefaultAfterDependency
{
    // @phpstan-ignore missingType.parameter (no type on purpose: the container sees no type here)
    public function __construct(public BootedService $boot, public $value = new NeedsBootedService())
    {
    }
}

// ==================== A Factory That Uses the Container When It Is Destroyed ====================

// A factory object whose destructor asks the container for an entry
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

// A subclass that provides ServiceInterface itself, through get() and has()
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

// A subclass with an event of its own
class ContainerWithBootEvent extends \Sodaho\Container\Container
{
    protected const array EVENTS = [...parent::EVENTS, 'boot'];

    public function boot(): void
    {
        $this->trigger('boot', ['container' => $this]);
    }
}

// ==================== Types Written Other Than the Class Is Declared ====================

// A class type written in another case than declared
class ServiceWithLowercaseType
{
    // @phpstan-ignore class.nameCase, class.nameCase (written in another case on purpose: parameter and property)
    public function __construct(public testservice $service)
    {
    }
}

// An optional interface type written in another case than declared
class ServiceWithOptionalLowercaseInterface
{
    // @phpstan-ignore interface.nameCase, interface.nameCase (written in another case on purpose: parameter and property)
    public function __construct(public ?serviceinterface $service = null)
    {
    }
}

// An optional dependency on a class that does not exist
class ServiceWithOptionalMissingClass
{
    // @phpstan-ignore class.notFound, class.notFound (missing on purpose: parameter and property)
    public function __construct(public ?\Missing\Thing $thing = null)
    {
    }
}

// ==================== Classes That Cannot Be Instantiated ====================

// Cannot be instantiated: its constructor is private
class ServiceWithPrivateConstructor
{
    private function __construct()
    {
    }
}

// Needs an abstract class
class ServiceNeedingAbstract
{
    public function __construct(public AbstractService $service)
    {
    }
}

// ==================== A Class That Would Have Got a Default, Had It Been Created ====================

// Would get the default for ServiceInterface, but its constructor throws
class ServiceWithOptionalInterfaceThatThrows
{
    public function __construct(public ?ServiceInterface $service = null)
    {
        throw new \RuntimeException('Constructor failed intentionally');
    }
}

// ==================== Two Parameters of One Type That Both Get Their Default ====================

// Two optional parameters of one type and one of another
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

// A default for ServiceInterface, then a dependency whose factory may register
class ServiceWithOptionalInterfaceThenService
{
    public function __construct(public ?ServiceInterface $service = null, public ?TestService $later = null)
    {
    }
}

// ==================== Services That Ask for a Container ====================

// Asks for this container class
class ServiceNeedingContainer
{
    public function __construct(public \Sodaho\Container\Container $container)
    {
    }
}

// Asks for the PSR-11 container interface
class ServiceNeedingPsrContainer
{
    public function __construct(public \Psr\Container\ContainerInterface $container)
    {
    }
}

// Asks for the PSR-11 container interface, optional
class ServiceWithOptionalPsrContainer
{
    public function __construct(public ?\Psr\Container\ContainerInterface $container = null)
    {
    }
}

// ==================== A Class in a Union With null or false ====================

// An interface or false
class ServiceWithFalseUnion
{
    public function __construct(public ServiceInterface|false $service = false)
    {
    }
}

// An interface, false or null
class ServiceWithFalseOrNullUnion
{
    public function __construct(public ServiceInterface|false|null $service = null)
    {
    }
}

// A class that cannot be built, or false
class ServiceWithUnbuildableFalseUnion
{
    public function __construct(public ServiceWithConfig|false $service = false)
    {
    }
}

// A class or false, without a default
class ServiceWithFalseUnionNoDefault
{
    public function __construct(public TestService|false $service)
    {
    }
}

// A class or a string: another union
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

// A PSR-11 container of another kind, as a dependency
class ForeignContainer implements \Psr\Container\ContainerInterface
{
    public function get(string $id): mixed
    {
        return null;
    }

    public function has(string $id): bool
    {
        return false;
    }
}

// Asks for the container of another kind
class ServiceNeedingForeignContainer
{
    public function __construct(public ForeignContainer $container)
    {
    }
}
