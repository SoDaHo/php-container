<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

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

    public function testNullableDependencyUsesNull(): void
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
}
