<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * Entries are singletons: set() and bind() throw for an entry that has been created.
 */
class RedefinitionTest extends TestCase
{
    public function testSetThrowsForAnEntryThatAlreadyExists(): void
    {
        $container = new Container();
        $container->set('service', fn () => new Fixtures\ConcreteService());
        $service = $container->get('service');

        try {
            $container->set('service', fn () => new Fixtures\AlternativeService());
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Cannot redefine 'service': the entry has been created.", $e->getMessage());
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
                "Cannot redefine '" . Fixtures\ServiceInterface::class . "': the entry has been created.",
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

    public function testBindingMayBeRedefinedAfterItsTargetFailed(): void
    {
        $container = new Container();
        // @phpstan-ignore argument.type (a typo in the class name, the case the README describes)
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

    public function testBindingReachedAsADependencyCannotBeRedefined(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $container->get(Fixtures\ControllerWithInterface::class);

        // The interface was only asked for as a dependency, but it is an entry now
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot redefine '" . Fixtures\ServiceInterface::class . "'");

        $container->bind(Fixtures\ServiceInterface::class, Fixtures\AlternativeService::class);
    }
}
