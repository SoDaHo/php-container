<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * bind(): an id resolves to what it is bound to, and the last registration for an id wins.
 */
class BindTest extends TestCase
{
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

    public function testBindOverridesSet(): void
    {
        $container = new Container();
        $container->set(Fixtures\ServiceInterface::class, fn () => new Fixtures\AlternativeService());
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testHasFollowsTheLastRegistration(): void
    {
        $container = new Container();
        $container->set('service', fn () => new Fixtures\ConcreteService());
        $container->bind('service', 'Missing\Implementation');

        $this->assertFalse($container->has('service'));

        $container->set('service', fn () => new Fixtures\ConcreteService());

        $this->assertTrue($container->has('service'));
    }

    public function testBindingIsInPlaceWhenTheFactoryItReplacesIsDestroyed(): void
    {
        // The container holds the only reference to the factory, whose destructor asks for the entry
        $container = new Container();
        $container->set(Fixtures\ServiceInterface::class, new Fixtures\FactoryThatAsksOnDestruct($container, Fixtures\ServiceInterface::class));

        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
        $this->assertTrue($container->has(Fixtures\ServiceInterface::class));
    }

    public function testBindingAClassToItselfReplacesItsFactory(): void
    {
        $container = new Container();
        $container->set(Fixtures\TestController::class, fn () => throw new \LogicException('The factory was replaced'));
        $container->bind(Fixtures\TestController::class, Fixtures\TestController::class);

        $this->assertInstanceOf(Fixtures\TestController::class, $container->get(Fixtures\TestController::class));
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
}
