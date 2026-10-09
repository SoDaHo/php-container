<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * Autowiring: which constructor parameters the container fills, and how it fails on the others.
 */
class AutowiringTest extends TestCase
{
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
        $this->expectExceptionMessage("': it has a union type.");

        $container->get(Fixtures\ServiceWithUnionNoDefault::class);
    }

    public function testIntersectionTypeWithoutDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("': it has an intersection type.");

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
        $this->expectExceptionMessage("': it has no type.");

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

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('is not instantiable: it is abstract.');

        $container->get(Fixtures\AbstractService::class);
    }

    public function testInterfaceWithoutBindingThrows(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('not found');

        $container->get(Fixtures\ServiceInterface::class);
    }

    public function testConstructorExceptionIsWrapped(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Failed to instantiate');

        $container->get(Fixtures\ServiceThrowsInConstructor::class);
    }

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

    public function testSubclassThatProvidesEntriesItselfIsAskedForDependencies(): void
    {
        $container = new Fixtures\ContainerWithFallback(new Fixtures\ConcreteService());

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ControllerWithInterface::class)->service
        );
    }
}
