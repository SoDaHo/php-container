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

    public function testCreateWithConfig(): void
    {
        $container = Container::create(['debug' => true]);

        $this->assertInstanceOf(Container::class, $container);
    }

    public function testSetDebugReturnsSelfForChaining(): void
    {
        $container = Container::create();
        $result = $container->setDebug(true);

        $this->assertSame($container, $result);
    }

    public function testEnableCacheReturnsSelfForChaining(): void
    {
        $cacheFile = sys_get_temp_dir() . '/container_test_' . uniqid() . '.php';
        $container = Container::create(['debug' => true]); // debug=true so no signature required
        $result = $container->enableCache($cacheFile);

        $this->assertSame($container, $result);
    }

    public function testEnableCacheWithSignature(): void
    {
        $cacheFile = sys_get_temp_dir() . '/container_test_' . uniqid() . '.php';
        $container = Container::create(['debug' => false]);
        $result = $container->enableCache($cacheFile, 'test-secret-key');

        $this->assertSame($container, $result);

        // Cleanup
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
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

    #[\PHPUnit\Framework\Attributes\RequiresPhp('>=8.2')]
    public function testIntersectionTypeWithDefaultUsesDefault(): void
    {
        require_once __DIR__ . '/Fixtures/Php82/Php82Fixtures.php';

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

    // ==================== Cache Methods Without Cache Configured ====================

    public function testSaveCacheWithoutCacheConfigured(): void
    {
        $container = new Container();
        $container->get(\stdClass::class);

        // Should not throw, just do nothing
        $container->saveCache();

        $this->assertTrue(true);
    }

    public function testClearCacheWithoutCacheConfigured(): void
    {
        $container = new Container();

        $result = $container->clearCache();

        $this->assertFalse($result);
    }

    // ==================== Unresolvable Dependencies ====================

    public function testUnresolvableDependencyThrowsContainerException(): void
    {
        $container = new Container();
        // ControllerWithInterface requires ServiceInterface, but no binding exists

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Cannot resolve dependency');

        $container->get(Fixtures\ControllerWithInterface::class);
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

    public function testDefinitionAndBindingForAnEntryThatAlreadyExistsHaveNoEffect(): void
    {
        $container = new Container();
        $container->set('service', fn () => new Fixtures\ConcreteService());
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $service = $container->get('service');
        $bound = $container->get(Fixtures\ServiceInterface::class);

        $container->set('service', fn () => new Fixtures\AlternativeService());
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\AlternativeService::class);

        $this->assertSame($service, $container->get('service'));
        $this->assertSame($bound, $container->get(Fixtures\ServiceInterface::class));
    }

    // ==================== has(): Aliases ====================

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

    public function testHasReturnsTrueForEntryThatAlreadyExistsWhateverTheBindingSaysNow(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $service = $container->get(Fixtures\ServiceInterface::class);

        $container->bind(Fixtures\ServiceInterface::class, 'Missing\Implementation');

        $this->assertTrue($container->has(Fixtures\ServiceInterface::class));
        $this->assertSame($service, $container->get(Fixtures\ServiceInterface::class));
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

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Error while creating service 'self': Circular dependency detected: self -> self");

        $container->get('self');
    }

    public function testFactoriesResolvingEachOtherAreDetected(): void
    {
        $container = new Container();
        $container->set('a', fn (Container $c) => $c->get('b'));
        $container->set('b', fn (Container $c) => $c->get('a'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected: a -> b -> a');

        $container->get('a');
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

    // ==================== Failures: Same Result Every Time ====================

    public function testWrappedExceptionsCarryTheOriginInTheDebugMessage(): void
    {
        $container = new Container();
        $container->set('broken', fn () => throw new \LogicException('Oops'));

        foreach (['broken', Fixtures\ServiceThrowsInConstructor::class] as $id) {
            try {
                $container->get($id);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $previous = $e->getPrevious();
                $this->assertNotNull($previous);
                $this->assertSame(
                    $previous::class . ' in ' . $previous->getFile() . ':' . $previous->getLine(),
                    $e->getDebugMessage()
                );
            }
        }
    }

    // ==================== Hooks: error ====================

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
            $this->assertSame("Error while creating service 'outer': Error while creating service 'inner': Oops", $e->getMessage());
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
}
