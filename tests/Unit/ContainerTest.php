<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * Unit tests for Container - isolated tests without external dependencies.
 */
class ContainerTest extends TestCase
{
    // ==================== Core: get/has/set ====================

    public function testGetReturnsSingleton(): void
    {
        $container = new Container();
        $obj1 = $container->get(\stdClass::class);
        $obj2 = $container->get(\stdClass::class);

        $this->assertInstanceOf(\stdClass::class, $obj1);
        $this->assertSame($obj1, $obj2, 'Container should return the same instance (Singleton)');
    }

    public function testManualDefinition(): void
    {
        $container = new Container();
        $container->set('db.host', fn () => 'localhost');

        $this->assertEquals('localhost', $container->get('db.host'));
    }

    public function testFactoryThatReturnsNullRunsOnlyOnce(): void
    {
        $container = new Container();
        $calls = 0;
        $container->set('nothing', function () use (&$calls) {
            $calls++;
            return null;
        });

        $this->assertNull($container->get('nothing'));
        $this->assertNull($container->get('nothing'));
        $this->assertSame(1, $calls);
    }

    public function testManualDefinitionIsSingleton(): void
    {
        $container = new Container();
        $container->set('service', fn () => new \stdClass());

        $obj1 = $container->get('service');
        $obj2 = $container->get('service');

        $this->assertSame($obj1, $obj2);
    }

    public function testHasReturnsTrueForExistingInstance(): void
    {
        $container = new Container();
        $container->get(\stdClass::class);

        $this->assertTrue($container->has(\stdClass::class));
    }

    public function testHasReturnsTrueForDefinition(): void
    {
        $container = new Container();
        $container->set('custom', fn () => 'value');

        $this->assertTrue($container->has('custom'));
    }

    public function testHasReturnsTrueForExistingClass(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(\stdClass::class));
    }

    public function testHasReturnsFalseForNonExistent(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('non.existent.service'));
    }

    public function testHasReturnsFalseForAbstractClass(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Fixtures\AbstractService::class));
    }

    public function testHasReturnsFalseForInterfaceWithoutBinding(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));
    }

    public function testHasReturnsTrueForBoundInterface(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertTrue($container->has(Fixtures\ServiceInterface::class));
    }

    public function testGetThrowsNotFoundForNonExistentClass(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get('NonExistentClass');
    }

    public function testFactoryReceivesContainer(): void
    {
        $container = new Container();
        $container->set('dep', fn () => 'dependency-value');
        $container->set('service', fn (Container $c) => 'got: ' . $c->get('dep'));

        $this->assertEquals('got: dependency-value', $container->get('service'));
    }

    public function testFactoryExceptionIsWrapped(): void
    {
        $container = new Container();
        $container->set('broken', fn () => throw new \RuntimeException('Factory failed'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Error while creating service 'broken'");

        $container->get('broken');
    }

    // ==================== Fluent API ====================

    public function testCreateFactoryMethod(): void
    {
        $container = Container::create();

        $this->assertInstanceOf(Container::class, $container);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function leftoverConfigs(): array
    {
        return [
            'debug' => [['debug' => false]],
            'cache' => [['cacheFile' => '/tmp/container.cache', 'cacheSignature' => 'key']],
            'null value' => [['cacheFile' => null]],
            'unknown key' => [['anything' => 1]],
            'list' => [[true]],
        ];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('leftoverConfigs')]
    public function testConstructorRejectsConfigLeftOverFromVersionOne(array $config): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('The container has no options: the cache and the debug option were removed in 2.0.');

        new Container($config);
    }

    public function testCreateRejectsConfigLeftOverFromVersionOne(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('The container has no options');

        Container::create(['debug' => true]);
    }

    public function testEmptyConfigIsAccepted(): void
    {
        $this->assertInstanceOf(Container::class, new Container([]));
        $this->assertInstanceOf(Container::class, Container::create([]));
    }

    public function testContainerDoesNotReadTheEnvironment(): void
    {
        $source = (string) file_get_contents((string) new \ReflectionClass(Container::class)->getFileName());

        $this->assertStringNotContainsString('getenv', $source);
        $this->assertStringNotContainsString('$_ENV', $source);
        $this->assertStringNotContainsString('$_SERVER', $source);
    }

    // ==================== Autowiring ====================

    public function testAutowiring(): void
    {
        $container = new Container();
        $controller = $container->get(Fixtures\TestController::class);

        $this->assertInstanceOf(Fixtures\TestController::class, $controller);
        $this->assertInstanceOf(Fixtures\TestService::class, $controller->service);
    }

    public function testAutowiringWithNestedDependencies(): void
    {
        $container = new Container();
        $deep = $container->get(Fixtures\DeepController::class);

        $this->assertInstanceOf(Fixtures\DeepController::class, $deep);
        $this->assertInstanceOf(Fixtures\TestController::class, $deep->controller);
        $this->assertInstanceOf(Fixtures\TestService::class, $deep->controller->service);
    }

    public function testAutowiringWithDefaultValues(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithDefaults::class);

        $this->assertEquals('default', $service->value);
        $this->assertEquals(42, $service->number);
    }

    public function testAutowiringFailsForPrimitives(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve primitive parameter 'apiKey'");

        $container->get(Fixtures\ServiceWithConfig::class);
    }

    public function testManualFixForPrimitives(): void
    {
        $container = new Container();
        $container->set(Fixtures\ServiceWithConfig::class, fn () => new Fixtures\ServiceWithConfig('secret-123'));

        $service = $container->get(Fixtures\ServiceWithConfig::class);
        $this->assertEquals('secret-123', $service->apiKey);
    }

    public function testOptionalDependencyUsesDefault(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithOptionalDep::class);

        $this->assertNull($service->optional);
    }

    public function testNullableDependencyWithDefaultUsesDefault(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithNullableDep::class);

        $this->assertNull($service->dep);
    }

    // ==================== Type Handling ====================

    public function testUnionTypeWithDefaultValue(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithUnionDefault::class);

        $this->assertEquals('default', $service->value);
    }

    public function testUnionTypeWithoutDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('union type, or intersection type');

        $container->get(Fixtures\ServiceWithUnionNoDefault::class);
    }

    public function testIntersectionTypeWithoutDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('intersection type');

        $container->get(Fixtures\ServiceWithIntersectionNoDefault::class);
    }

    public function testIntersectionTypeWithDefaultUsesDefault(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithIntersectionNullableDefault::class);

        $this->assertNull($service->value);
    }

    public function testNoTypeHintWithDefaultValue(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithNoTypeDefault::class);

        $this->assertEquals('default', $service->value);
    }

    public function testNoTypeHintWithoutDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('union type, or intersection type');

        $container->get(Fixtures\ServiceWithNoTypeNoDefault::class);
    }

    public function testVariadicParameterThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("variadic parameter '...services'");

        $container->get(Fixtures\ServiceWithVariadic::class);
    }

    public function testVariadicParameterWithManualDefinition(): void
    {
        $container = new Container();
        $container->set(Fixtures\ServiceWithVariadic::class, fn (Container $c) => new Fixtures\ServiceWithVariadic(
            $c->get(Fixtures\TestService::class),
            $c->get(Fixtures\TestService::class),
        ));

        $service = $container->get(Fixtures\ServiceWithVariadic::class);

        $this->assertCount(2, $service->services);
        $this->assertSame($service->services[0], $service->services[1]);
    }

    public function testAbstractClassThrowsException(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('not instantiable');

        $container->get(Fixtures\AbstractService::class);
    }

    public function testInterfaceWithoutBindingThrows(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('not found');

        $container->get(Fixtures\ServiceInterface::class);
    }

    // ==================== bind() ====================

    public function testBindInterfaceToImplementation(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $service = $container->get(Fixtures\ServiceInterface::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $service);
    }

    public function testBindReturnsSelfForChaining(): void
    {
        $container = new Container();
        $result = $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertSame($container, $result);
    }

    public function testBindResolvesDependenciesAutomatically(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $controller = $container->get(Fixtures\ControllerWithInterface::class);

        $this->assertInstanceOf(Fixtures\ControllerWithInterface::class, $controller);
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $controller->service);
    }

    public function testBindIsSingleton(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $service1 = $container->get(Fixtures\ServiceInterface::class);
        $service2 = $container->get(Fixtures\ServiceInterface::class);

        $this->assertSame($service1, $service2);
    }

    public function testMultipleBindings(): void
    {
        $container = Container::create()
            ->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class)
            ->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\LoggerInterface::class));
    }

    public function testSetOverridesBind(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $container->set(Fixtures\ServiceInterface::class, fn () => new Fixtures\AlternativeService());

        $service = $container->get(Fixtures\ServiceInterface::class);

        $this->assertInstanceOf(Fixtures\AlternativeService::class, $service);
    }

    public function testEveryBindingOnTheWayIsAnEntryOfItsOwn(): void
    {
        $container = new Container();
        $container->bind('first', 'second');
        $container->bind('second', Fixtures\ConcreteService::class);

        $service = $container->get('first');

        // 'second' was never asked for, but it exists now
        try {
            $container->bind('second', Fixtures\AlternativeService::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString("Cannot redefine 'second'", $e->getMessage());
        }

        $this->assertSame($service, $container->get('second'));
        $this->assertSame($service, $container->get(Fixtures\ConcreteService::class));
    }

    // ==================== Circular Dependency Detection ====================

    public function testCircularDependencyIsDetected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $container->get(Fixtures\CircularA::class);
    }

    public function testCircularDependencyChainIsShown(): void
    {
        $container = new Container();

        try {
            $container->get(Fixtures\CircularA::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString('CircularA', $e->getMessage());
            $this->assertStringContainsString('CircularB', $e->getMessage());
        }
    }

    public function testSelfDependencyIsDetected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $container->get(Fixtures\SelfDependent::class);
    }

    // ==================== Constructor Exception Handling ====================

    public function testConstructorExceptionIsWrapped(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Failed to instantiate');

        $container->get(Fixtures\ServiceThrowsInConstructor::class);
    }

    // ==================== Unresolvable Dependencies ====================

    public function testUnresolvableDependencyThrowsContainerException(): void
    {
        $container = new Container();
        // ControllerWithInterface requires ServiceInterface, but no binding exists

        try {
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            // Not a NotFoundException: the class that was asked for exists
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertStringStartsWith("Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter '", $e->getMessage());
            $this->assertInstanceOf(NotFoundException::class, $e->getPrevious());
        }
    }

    public function testNoDependencyIsCreatedWhenALaterParameterCannotBeResolved(): void
    {
        $container = new Container();
        $created = [];
        $container->on('resolve', function (array $data) use (&$created) {
            $created[] = $data['id'];
        });

        try {
            $container->get(Fixtures\ServiceWithDependencyBeforePrimitive::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringContainsString("primitive parameter 'apiKey'", $e->getMessage());
        }

        $this->assertSame([], $created);
    }

    // ==================== Hooks ====================

    public function testOnReturnsContainerForChaining(): void
    {
        $container = new Container();

        $result = $container->on('resolve', fn () => null);

        $this->assertSame($container, $result);
    }

    public function testResolveHookIsFired(): void
    {
        $container = new Container();
        $firedEvents = [];

        $container->on('resolve', function (array $data) use (&$firedEvents) {
            $firedEvents[] = $data;
        });

        $container->get(\stdClass::class);

        $this->assertCount(1, $firedEvents);
        $this->assertEquals(\stdClass::class, $firedEvents[0]['id']);
        $this->assertInstanceOf(\stdClass::class, $firedEvents[0]['instance']);
    }

    public function testResolveHookNotFiredForSingletonHit(): void
    {
        $container = new Container();
        $callCount = 0;

        $container->on('resolve', function () use (&$callCount) {
            $callCount++;
        });

        $container->get(\stdClass::class);  // First call - fires hook
        $container->get(\stdClass::class);  // Second call - singleton, no hook

        $this->assertEquals(1, $callCount);
    }

    public function testErrorHookIsFiredOnFactoryException(): void
    {
        $container = new Container();
        $firedErrors = [];

        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        $container->set('broken', fn () => throw new \RuntimeException('Oops'));

        try {
            $container->get('broken');
        } catch (ContainerException) {
            // Expected
        }

        $this->assertCount(1, $firedErrors);
        $this->assertEquals('broken', $firedErrors[0]['id']);
        $this->assertInstanceOf(\RuntimeException::class, $firedErrors[0]['exception']);
    }

    public function testErrorHookIsFiredOnConstructorException(): void
    {
        $container = new Container();
        $firedErrors = [];

        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get(Fixtures\ServiceThrowsInConstructor::class);
        } catch (ContainerException) {
            // Expected
        }

        $this->assertCount(1, $firedErrors);
        $this->assertInstanceOf(\RuntimeException::class, $firedErrors[0]['exception']);
    }

    public function testHookExceptionBubblesUp(): void
    {
        $container = new Container();

        $container->on('resolve', function () {
            throw new \RuntimeException('Hook failed');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hook failed');

        $container->get(\stdClass::class);
    }

    // ==================== Redefining an Entry That Exists ====================

    public function testSetThrowsForAnEntryThatAlreadyExists(): void
    {
        $container = new Container();
        $container->set('service', fn () => new Fixtures\ConcreteService());
        $service = $container->get('service');

        try {
            $container->set('service', fn () => new Fixtures\AlternativeService());
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Cannot redefine 'service': the entry has been created or is being created.", $e->getMessage());
        }

        $this->assertSame($service, $container->get('service'));
    }

    public function testBindThrowsForAnEntryThatAlreadyExists(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $service = $container->get(Fixtures\ServiceInterface::class);

        try {
            $container->bind(Fixtures\ServiceInterface::class, Fixtures\AlternativeService::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot redefine '" . Fixtures\ServiceInterface::class . "': the entry has been created or is being created.",
                $e->getMessage()
            );
        }

        $this->assertSame($service, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testEntryCreatedThroughABindingCannotBeRedefinedEither(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $container->get(Fixtures\ServiceInterface::class);

        // The implementation exists as an entry of its own, and the interface as well
        foreach ([Fixtures\ConcreteService::class, Fixtures\ServiceInterface::class] as $id) {
            try {
                $container->set($id, fn () => new Fixtures\AlternativeService());
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertStringContainsString("Cannot redefine '$id'", $e->getMessage());
            }
        }
    }

    public function testEntryThatIsNullCannotBeRedefinedEither(): void
    {
        $container = new Container();
        $container->set('nothing', fn () => null);
        $container->get('nothing');

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot redefine 'nothing'");

        $container->set('nothing', fn () => 'something');
    }

    public function testEntryCannotBeRedefinedFromInsideItsOwnFactory(): void
    {
        $container = new Container();
        $container->set('service', function (Container $c) {
            $c->set('service', fn () => new Fixtures\AlternativeService());

            return new Fixtures\ConcreteService();
        });

        try {
            $container->get('service');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Error while creating service 'service'.", $e->getMessage());
            $this->assertSame(
                "Cannot redefine 'service': the entry has been created or is being created.",
                $e->getPrevious()?->getMessage()
            );
        }
    }

    /**
     * @return array<string, array{callable(Container): mixed, class-string}>
     */
    public static function redefinitionsOfABinding(): array
    {
        $bind = fn (Container $c) => $c->bind('needs.logger', 'Missing\Implementation');
        $set = fn (Container $c) => $c->set('needs.logger', fn () => new \stdClass());

        return [
            'bind() when the target is announced' => [$bind, Fixtures\NeedsLogger::class],
            'set() when the target is announced' => [$set, Fixtures\NeedsLogger::class],
            // One get() further in: the binding the outer get() follows stays locked
            'bind() when a dependency of the target is announced' => [$bind, Fixtures\FileLogger::class],
            'set() when a dependency of the target is announced' => [$set, Fixtures\FileLogger::class],
        ];
    }

    /**
     * @param callable(Container): mixed $redefine
     * @param class-string $announced
     */
    #[DataProvider('redefinitionsOfABinding')]
    public function testBindingCannotBeRedefinedWhileItsTargetIsBeingCreated(callable $redefine, string $announced): void
    {
        // 'needs.logger' -> NeedsLogger, which itself gets its logger through another binding
        $container = new Container();
        $container->bind('needs.logger', Fixtures\NeedsLogger::class);
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container->on('resolve', function (array $data) use ($container, $redefine, $announced) {
            if ($data['id'] === $announced) {
                $redefine($container);
            }
        });

        try {
            $container->get('needs.logger');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Cannot redefine 'needs.logger': the entry has been created or is being created.", $e->getMessage());
        }

        // What get() returns and what has() says stay in step
        $this->assertTrue($container->has('needs.logger'));
        $this->assertInstanceOf(Fixtures\NeedsLogger::class, $container->get('needs.logger'));
    }

    public function testErrorHookCannotRedefineTheEntryThatIsFailing(): void
    {
        $container = new Container();
        $container->on('error', function (array $data) use ($container) {
            $container->set($data['id'], fn () => new \stdClass());
        });

        try {
            $container->get('Missing\Service');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Cannot redefine 'Missing\Service': the entry has been created or is being created.", $e->getMessage());
        }

        // Once get() has failed, the replacement is accepted
        $container->set('Missing\Service', fn () => new \stdClass());

        $this->assertInstanceOf(\stdClass::class, $container->get('Missing\Service'));
    }

    public function testBindingMayBeRedefinedAfterItsTargetFailed(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, 'Missing\Implementation');

        try {
            $container->get(Fixtures\ServiceInterface::class);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException) {
            // Expected: nothing was created
        }

        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testEntryMayBeRedefinedUntilItIsCreated(): void
    {
        $container = new Container();
        $container->set('service', fn () => new Fixtures\ConcreteService());
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $container->has('service');
        $container->has(Fixtures\ServiceInterface::class);

        $container->set('service', fn () => new Fixtures\AlternativeService());
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\AlternativeService::class);

        $this->assertInstanceOf(Fixtures\AlternativeService::class, $container->get('service'));
        $this->assertInstanceOf(Fixtures\AlternativeService::class, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testEntryMayBeDefinedAgainAfterItFailed(): void
    {
        $container = new Container();
        $container->set('service', fn () => throw new \RuntimeException('Oops'));

        try {
            $container->get('service');
            $this->fail('Expected ContainerException');
        } catch (ContainerException) {
            // Expected: nothing was created
        }

        $container->set('service', fn () => new Fixtures\ConcreteService());

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get('service'));
    }

    public function testRedefinitionIsNotReportedToTheErrorHook(): void
    {
        $container = new Container();
        $fired = 0;
        $container->on('error', function () use (&$fired) {
            $fired++;
        });
        $container->get(\stdClass::class);

        try {
            $container->set(\stdClass::class, fn () => new \stdClass());
            $this->fail('Expected ContainerException');
        } catch (ContainerException) {
            // Expected
        }

        $this->assertSame(0, $fired, "The 'error' hook is for failures of get()");
    }

    // ==================== has() ====================

    public function testHasReturnsFalseForAliasToMissingClass(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, 'Missing\Implementation');

        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));

        $this->expectException(NotFoundException::class);
        $container->get(Fixtures\ServiceInterface::class);
    }

    public function testHasFollowsAliasChain(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $this->assertFalse($container->has(Fixtures\FirstInterface::class));

        $container->bind(Fixtures\SecondInterface::class, Fixtures\ConcreteService::class);
        $this->assertTrue($container->has(Fixtures\FirstInterface::class));
    }

    public function testHasReturnsTrueForAliasToDefinition(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, 'service.custom');
        $container->set('service.custom', fn () => new Fixtures\ConcreteService());

        $this->assertTrue($container->has(Fixtures\ServiceInterface::class));
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testHasReturnsFalseForAliasCycle(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ConcreteService::class, Fixtures\AlternativeService::class);
        $container->bind(Fixtures\AlternativeService::class, Fixtures\ConcreteService::class);

        $this->assertFalse($container->has(Fixtures\ConcreteService::class));
        $this->assertFalse($container->has(Fixtures\AlternativeService::class));
    }

    public function testHasReturnsTrueForInstanceBehindAlias(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $container->set(Fixtures\SecondInterface::class, fn () => 'value');
        $container->get(Fixtures\SecondInterface::class);

        $this->assertTrue($container->has(Fixtures\FirstInterface::class));
    }

    // ==================== Cycles Outside of Autowiring ====================

    public function testCircularDependencyChainIsExact(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected: ' . Fixtures\CircularA::class . ' -> ' . Fixtures\CircularB::class . ' -> ' . Fixtures\CircularA::class
        );

        $container->get(Fixtures\CircularA::class);
    }

    public function testAliasCycleIsDetected(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $container->bind(Fixtures\SecondInterface::class, Fixtures\FirstInterface::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected: ' . Fixtures\FirstInterface::class . ' -> ' . Fixtures\SecondInterface::class . ' -> ' . Fixtures\FirstInterface::class
        );

        $container->get(Fixtures\FirstInterface::class);
    }

    public function testBindingAClassToItselfAutowiresIt(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ConcreteService::class, Fixtures\ConcreteService::class);
        $this->assertTrue($container->has(Fixtures\ConcreteService::class));

        $service = $container->get(Fixtures\ConcreteService::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $service);
        $this->assertSame($service, $container->get(Fixtures\ConcreteService::class));
        $this->assertTrue($container->has(Fixtures\ConcreteService::class));
    }

    public function testFactoryResolvingItsOwnIdIsDetected(): void
    {
        $container = new Container();
        $container->set('self', fn (Container $c) => $c->get('self'));

        try {
            $container->get('self');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Error while creating service 'self'.", $e->getMessage());
            $this->assertInstanceOf(ContainerException::class, $e->getPrevious());
            $this->assertSame('Circular dependency detected: self -> self', $e->getPrevious()->getMessage());
        }
    }

    public function testFactoriesResolvingEachOtherAreDetected(): void
    {
        $container = new Container();
        $container->set('a', fn (Container $c) => $c->get('b'));
        $container->set('b', fn (Container $c) => $c->get('a'));

        try {
            $container->get('a');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Error while creating service 'a'.", $e->getMessage());
            $this->assertSame("Error while creating service 'b'.", $e->getPrevious()?->getMessage());
            $this->assertSame('Circular dependency detected: a -> b -> a', $e->getPrevious()?->getPrevious()?->getMessage());
        }
    }

    public function testCycleThroughBindingIsDetected(): void
    {
        $container = new Container();
        // NeedsLogger needs LoggerInterface, which is bound back to NeedsLogger
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\NeedsLogger::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            'Circular dependency detected: ' . Fixtures\NeedsLogger::class . ' -> ' . Fixtures\LoggerInterface::class . ' -> ' . Fixtures\NeedsLogger::class
        );

        $container->get(Fixtures\NeedsLogger::class);
    }

    public function testContainerStaysUsableAfterDetectedCycle(): void
    {
        $container = new Container();
        $messages = [];

        foreach ([1, 2] as $attempt) {
            try {
                $container->get(Fixtures\CircularA::class);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame($messages[0], $messages[1], 'The chain must not keep ids of an earlier, failed attempt');
        $this->assertInstanceOf(Fixtures\TestService::class, $container->get(Fixtures\TestService::class));
    }

    // ==================== Optional Dependencies ====================

    public function testNullableDependencyWithoutDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve dependency '" . Fixtures\NonExistentInterface::class . "' for parameter 'dep'");

        $container->get(Fixtures\ServiceWithNullableNoDefault::class);
    }

    public function testOptionalEnumDependencyUsesDefault(): void
    {
        $container = new Container();

        $this->assertSame(Fixtures\Mode::Safe, $container->get(Fixtures\ServiceWithEnumDefault::class)->mode);
    }

    public function testOptionalAbstractDependencyUsesDefault(): void
    {
        $container = new Container();

        $this->assertNull($container->get(Fixtures\ServiceWithOptionalAbstract::class)->service);
    }

    public function testOptionalDependencyUsesBindingWhenThereIsOne(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ServiceWithOptionalInterface::class)->service
        );
    }

    public function testOptionalDependencyThatExistsButCannotBeBuiltStaysAnError(): void
    {
        $container = new Container();

        // ServiceWithConfig is instantiable but needs a string: a wiring mistake, not a missing service
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve primitive parameter 'apiKey'");

        $container->get(Fixtures\ServiceWithOptionalBroken::class);
    }

    public function testObjectDefaultIsUsedWhenNothingIsBound(): void
    {
        $container = new Container();

        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\ServiceWithObjectDefault::class)->logger);
    }

    public function testDefaultOfAnUntypedParameterMayBeAnObjectOrAnEnumCase(): void
    {
        $container = new Container();

        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\ServiceWithUntypedObjectDefault::class)->logger);
        $this->assertSame(Fixtures\Mode::Safe, $container->get(Fixtures\ServiceWithUntypedEnumDefault::class)->mode);
    }

    public function testObjectDefaultIsOnlyCreatedWhenItIsUsed(): void
    {
        Fixtures\CountingLogger::$created = 0;

        $bound = new Container()->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $bound->get(Fixtures\ServiceWithCountingDefault::class)->logger);
        $this->assertSame(0, Fixtures\CountingLogger::$created);

        $unbound = new Container();
        $this->assertInstanceOf(Fixtures\CountingLogger::class, $unbound->get(Fixtures\ServiceWithCountingDefault::class)->logger);
        $this->assertSame(1, Fixtures\CountingLogger::$created);
    }

    public function testDefaultIsEvaluatedAfterTheDependenciesInFrontOfIt(): void
    {
        Fixtures\BootedService::$booted = false;

        $service = new Container()->get(Fixtures\ServiceWithDefaultAfterDependency::class);

        $this->assertInstanceOf(Fixtures\NeedsBootedService::class, $service->value);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function classesWithThrowingDefault(): array
    {
        return [
            'optional dependency' => [Fixtures\ServiceWithThrowingDefault::class],
            'untyped parameter' => [Fixtures\ServiceWithThrowingUntypedDefault::class],
        ];
    }

    /**
     * @param class-string $id
     */
    #[DataProvider('classesWithThrowingDefault')]
    public function testDefaultThatThrowsIsReportedLikeAFailingConstructor(string $id): void
    {
        $container = new Container();
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data;
        });

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Failed to instantiate '$id'.", $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringEndsWith(': Default failed intentionally', (string) $e->getDebugMessage());
        }

        $this->assertCount(1, $errors);
        $this->assertSame($id, $errors[0]['id']);
        $this->assertInstanceOf(\RuntimeException::class, $errors[0]['exception']);
    }

    // ==================== Failures: Same Result Every Time ====================

    public function testSecondGetAfterConstructorFailureFailsTheSameWay(): void
    {
        $container = new Container();
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data['exception'];
        });
        $messages = [];

        foreach ([1, 2] as $attempt) {
            try {
                $container->get(Fixtures\ServiceThrowsInConstructor::class);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $messages[] = $e->getMessage();
                $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            }
        }

        $this->assertSame(
            "Failed to instantiate '" . Fixtures\ServiceThrowsInConstructor::class . "'.",
            $messages[0]
        );
        $this->assertSame($messages[0], $messages[1]);
        $this->assertCount(2, $errors);
    }

    // ==================== Messages ====================

    public function testMessageOfAWrappedExceptionStaysOutOfGetMessage(): void
    {
        $container = new Container();
        $container->set('broken', fn () => throw new \LogicException('mysql://app:secret@db/app refused'));
        $messages = [
            'broken' => "Error while creating service 'broken'.",
            Fixtures\ServiceThrowsInConstructor::class => "Failed to instantiate '" . Fixtures\ServiceThrowsInConstructor::class . "'.",
        ];

        foreach ($messages as $id => $message) {
            try {
                $container->get($id);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $previous = $e->getPrevious();
                $this->assertNotNull($previous);
                $this->assertSame($message, $e->getMessage());
                $this->assertSame(
                    $previous::class . ' in ' . $previous->getFile() . ':' . $previous->getLine() . ': ' . $previous->getMessage(),
                    $e->getDebugMessage()
                );
            }
        }
    }

    public function testDebugMessageNamesEveryCauseInOrder(): void
    {
        $container = new Container();
        $container->set('outer', fn (Container $c) => $c->get('inner'));
        $container->set('inner', fn () => throw new \RuntimeException('Oops', 0, new \LogicException('Root cause')));

        try {
            $container->get('outer');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $inner = $e->getPrevious();
            $this->assertInstanceOf(ContainerException::class, $inner);
            $thrown = $inner->getPrevious();
            $this->assertInstanceOf(\RuntimeException::class, $thrown);
            $root = $thrown->getPrevious();
            $this->assertInstanceOf(\LogicException::class, $root);

            $this->assertSame(
                ContainerException::class . ' in ' . $inner->getFile() . ':' . $inner->getLine() . ": Error while creating service 'inner'."
                . ' <- RuntimeException in ' . $thrown->getFile() . ':' . $thrown->getLine() . ': Oops'
                . ' <- LogicException in ' . $root->getFile() . ':' . $root->getLine() . ': Root cause',
                $e->getDebugMessage()
            );
            $this->assertSame(
                'RuntimeException in ' . $thrown->getFile() . ':' . $thrown->getLine() . ': Oops'
                . ' <- LogicException in ' . $root->getFile() . ':' . $root->getLine() . ': Root cause',
                $inner->getDebugMessage()
            );
        }
    }

    public function testExceptionsTheContainerRaisesItselfHaveNoDebugMessage(): void
    {
        $container = new Container();

        foreach (['Missing\Service', Fixtures\AbstractService::class, Fixtures\CircularA::class, Fixtures\ControllerWithInterface::class] as $id) {
            try {
                $container->get($id);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertNull($e->getDebugMessage());
            }
        }
    }

    // ==================== Hooks: error ====================

    /**
     * @return array<string, array{string, string, class-string<\Throwable>, string}>
     */
    public static function failingIds(): array
    {
        return [
            'class not found' => ['Missing\Service', 'Missing\Service', NotFoundException::class, 'not found'],
            'unbound interface' => [Fixtures\ServiceInterface::class, Fixtures\ServiceInterface::class, NotFoundException::class, 'not found'],
            'abstract class' => [Fixtures\AbstractService::class, Fixtures\AbstractService::class, ContainerException::class, 'not instantiable'],
            'circular' => [Fixtures\CircularA::class, Fixtures\CircularA::class, ContainerException::class, 'Circular dependency detected'],
            'variadic' => [Fixtures\ServiceWithVariadic::class, Fixtures\ServiceWithVariadic::class, ContainerException::class, 'variadic parameter'],
            'no type' => [Fixtures\ServiceWithNoTypeNoDefault::class, Fixtures\ServiceWithNoTypeNoDefault::class, ContainerException::class, 'No type hint'],
            'union type' => [Fixtures\ServiceWithUnionNoDefault::class, Fixtures\ServiceWithUnionNoDefault::class, ContainerException::class, 'No type hint'],
            'primitive' => [Fixtures\ServiceWithConfig::class, Fixtures\ServiceWithConfig::class, ContainerException::class, 'primitive parameter'],
            // Reported where it happens: for the dependency that is missing, not for the class that needs it
            'missing dependency' => [Fixtures\ControllerWithInterface::class, Fixtures\ServiceInterface::class, NotFoundException::class, 'not found'],
            'failing nested dependency' => [Fixtures\ServiceWithOptionalBroken::class, Fixtures\ServiceWithConfig::class, ContainerException::class, 'primitive parameter'],
        ];
    }

    /**
     * @param class-string<\Throwable> $reportedClass
     */
    #[DataProvider('failingIds')]
    public function testErrorHookIsFiredOnceWhereTheFailureHappens(string $id, string $reportedId, string $reportedClass, string $reportedMessage): void
    {
        $container = new Container();
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException) {
            // Expected
        }

        $this->assertCount(1, $firedErrors);
        $this->assertSame($reportedId, $firedErrors[0]['id']);
        $this->assertSame($reportedClass, $firedErrors[0]['exception']::class);
        $this->assertStringContainsString($reportedMessage, $firedErrors[0]['exception']->getMessage());
    }

    public function testErrorHookIsFiredForACycleOfBindings(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $container->bind(Fixtures\SecondInterface::class, Fixtures\FirstInterface::class);
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get(Fixtures\FirstInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertCount(1, $firedErrors);
            $this->assertSame(Fixtures\FirstInterface::class, $firedErrors[0]['id']);
            $this->assertSame($e, $firedErrors[0]['exception']);
        }
    }

    public function testErrorHookNamesTheMissingClassBehindABinding(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, 'Missing\Implementation');
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringStartsWith("Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter '", $e->getMessage());
            $this->assertCount(1, $firedErrors);
            $this->assertSame('Missing\Implementation', $firedErrors[0]['id']);
            $this->assertSame($e->getPrevious(), $firedErrors[0]['exception']);
            $this->assertSame("Class or service 'Missing\Implementation' not found.", $firedErrors[0]['exception']->getMessage());
        }
    }

    public function testErrorHookIsNotFiredWhenAnOptionalDependencyFallsBackToItsDefault(): void
    {
        $container = new Container();
        $fired = 0;
        $container->on('error', function () use (&$fired) {
            $fired++;
        });

        $container->get(Fixtures\ServiceWithOptionalDep::class);
        $container->get(Fixtures\ServiceWithEnumDefault::class);
        $container->get(Fixtures\ServiceWithOptionalAbstract::class);

        $this->assertSame(0, $fired);
    }

    public function testErrorHookReportsEachFactoryOnTheWayUp(): void
    {
        $container = new Container();
        $ids = [];
        $container->on('error', function (array $data) use (&$ids) {
            $ids[] = $data['id'];
        });
        $container->set('outer', fn (Container $c) => $c->get('inner'));
        $container->set('inner', fn () => throw new \RuntimeException('Oops'));

        try {
            $container->get('outer');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Error while creating service 'outer'.", $e->getMessage());
        }

        $this->assertSame(['inner', 'outer'], $ids);
    }

    // ==================== Hooks: resolve ====================

    public function testResolveHookReceivesWhateverAFactoryReturns(): void
    {
        $container = new Container();
        $events = [];
        $container->on('resolve', function (array $data) use (&$events) {
            $events[] = $data;
        });
        $container->set('app.name', fn () => 'My Application');

        $container->get('app.name');

        $this->assertSame([['id' => 'app.name', 'instance' => 'My Application']], $events);
    }

    public function testResolveHookIsFiredForTheImplementationNotForTheAlias(): void
    {
        $container = new Container();
        $ids = [];
        $container->on('resolve', function (array $data) use (&$ids) {
            $ids[] = $data['id'];
        });
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $container->get(Fixtures\ServiceInterface::class);
        $container->get(Fixtures\ServiceInterface::class);

        $this->assertSame([Fixtures\ConcreteService::class], $ids);
    }

    public function testTwoHooksForOneEventBothRunInOrder(): void
    {
        $container = new Container();
        $order = [];
        $container->on('resolve', function () use (&$order) {
            $order[] = 'first';
        });
        $container->on('resolve', function () use (&$order) {
            $order[] = 'second';
        });

        $container->get(\stdClass::class);

        $this->assertSame(['first', 'second'], $order);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function resolvableIds(): array
    {
        return [
            'class without constructor' => [Fixtures\TestService::class],
            'class with constructor' => [Fixtures\TestController::class],
            'factory' => ['factory'],
        ];
    }

    #[DataProvider('resolvableIds')]
    public function testResolveHookExceptionIsNotReportedAsAFailedInstantiation(string $id): void
    {
        $container = new Container();
        $container->set('factory', fn () => new \stdClass());
        $errors = 0;
        $container->on('error', function () use (&$errors) {
            $errors++;
        });
        $container->on('resolve', function (array $data) use ($id) {
            if ($data['id'] === $id) {
                throw new \RuntimeException('Hook failed');
            }
        });

        try {
            $container->get($id);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('Hook failed', $e->getMessage());
        }

        $this->assertSame(0, $errors);
    }

    public function testResolveHookMayAskForTheInterfaceItsImplementationWasBoundTo(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $seen = [];
        $container->on('resolve', function (array $data) use ($container, &$seen) {
            // The outer get() of the interface is still running at this point
            $seen[] = $container->get(Fixtures\ServiceInterface::class);
        });

        $service = $container->get(Fixtures\ServiceInterface::class);

        $this->assertSame([$service], $seen);
    }

    public function testErrorHookThatUsesTheContainerIsNotCalledForItsOwnFailure(): void
    {
        // The logger the hook asks for is what cannot be built
        $container = new Container();
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\LoggerNeedingTransport::class);
        $calls = 0;
        $failedInsideHook = null;
        $container->on('error', function () use ($container, &$calls, &$failedInsideHook) {
            $calls++;
            try {
                $container->get(Fixtures\LoggerInterface::class);
            } catch (ContainerException $e) {
                $failedInsideHook = $e->getMessage();
            }
        });

        try {
            $container->get(Fixtures\NeedsLogger::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot resolve dependency '" . Fixtures\NonExistentInterface::class . "' for parameter 'transport' in class '" . Fixtures\LoggerNeedingTransport::class . "'.",
                $e->getMessage()
            );
        }

        $this->assertSame(1, $calls);
        $this->assertStringStartsWith('Circular dependency detected: ', (string) $failedInsideHook);

        // The guard is released afterwards: the next failure is reported again
        try {
            $container->get('Missing\Service');
        } catch (NotFoundException) {
            // Expected
        }
        $this->assertSame(2, $calls);
    }

    public function testErrorHookExceptionForAMissingDependencyKeepsTheWrapperIfItIsANotFoundException(): void
    {
        // What get() says stays true (the dependency cannot be resolved); the hook's exception is the cause
        $container = new Container();
        $thrown = new NotFoundException('Thrown by the hook');
        $container->on('error', fn () => throw $thrown);

        try {
            // ControllerWithInterface needs ServiceInterface, which is not bound
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertStringStartsWith("Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "'", $e->getMessage());
            $this->assertSame($thrown, $e->getPrevious());
        }
    }

    public function testErrorHookExceptionForAMissingDependencyPassesIfItIsAnythingElse(): void
    {
        $container = new Container();
        $thrown = new \RuntimeException('log sink unavailable');
        $container->on('error', fn () => throw $thrown);

        try {
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }
    }

    public function testSubclassThatProvidesEntriesItselfIsAskedForDependencies(): void
    {
        $container = new Fixtures\ContainerWithFallback(new Fixtures\ConcreteService());

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ControllerWithInterface::class)->service
        );
    }

    public function testErrorHookIsCalledAgainAfterItThrew(): void
    {
        $container = new Container();
        $calls = 0;
        $container->on('error', function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('log sink unavailable');
        });

        foreach ([1, 2] as $attempt) {
            try {
                $container->get('Missing\Service');
                $this->fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                $this->assertSame('log sink unavailable', $e->getMessage());
            }
        }

        $this->assertSame(2, $calls);
    }

    public function testErrorHookExceptionReplacesTheContainerException(): void
    {
        $container = new Container();
        $container->on('error', fn () => throw new \RuntimeException('log sink unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('log sink unavailable');

        $container->get('Missing\Service');
    }
}
