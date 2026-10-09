<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * Entries are singletons: set() and bind() throw for an entry that has been created or is being created.
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
        $bind = fn (Container $c) => $c->bind(Fixtures\FirstInterface::class, Fixtures\ConcreteService::class);
        $set = fn (Container $c) => $c->set(Fixtures\FirstInterface::class, fn () => new \stdClass());

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
        // FirstInterface -> NeedsLogger, which itself gets its logger through another binding
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\NeedsLogger::class);
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container->on('resolve', function (array $data) use ($container, $redefine, $announced) {
            if ($data['id'] === $announced) {
                $redefine($container);
            }
        });

        try {
            $container->get(Fixtures\FirstInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot redefine '" . Fixtures\FirstInterface::class . "': the entry has been created or is being created.",
                $e->getMessage()
            );
        }

        // What get() returns and what has() says stay in step
        $this->assertTrue($container->has(Fixtures\FirstInterface::class));
        $this->assertInstanceOf(Fixtures\NeedsLogger::class, $container->get(Fixtures\FirstInterface::class));
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
