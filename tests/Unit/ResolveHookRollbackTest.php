<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * A resolve hook that throws undoes its entry together with every entry created while it ran: one of them may hold
 * the instance that never passed the hook. Entries created before stay, and so do the type locks they brought.
 */
class ResolveHookRollbackTest extends TestCase
{
    use ResolveHookFailure;

    /**
     * A resolve hook that, the first time it sees $id, runs $before and throws.
     *
     * @param callable(Container): mixed $before
     */
    private function failOnce(Container $container, string $id, callable $before): void
    {
        $failed = false;
        $container->on('resolve', function (array $data) use ($container, $id, $before, &$failed) {
            if ($data['id'] === $id && !$failed) {
                $failed = true;
                $before($container);
                throw new \RuntimeException('Hook failed');
            }
        });
    }

    public function testClassTheHookCreatedWithTheEntryIsUndoneAsWell(): void
    {
        // The hook asks for a class that needs the entry: it gets the instance that is about to be undone
        $container = new Container();
        $this->failOnce($container, Fixtures\TestService::class, fn (Container $c) => $c->get(Fixtures\TestController::class));

        $this->getFails($container, Fixtures\TestService::class);
        $controller = $container->get(Fixtures\TestController::class);

        // One instance of the service: the one the controller holds is the one the container returns
        $this->assertSame($container->get(Fixtures\TestService::class), $controller->service);
    }

    public function testFactoryEntryTheHookCreatedThroughABindingIsUndoneAsWell(): void
    {
        $container = new Container();
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $container->set('audit', fn (Container $c) => [$c->get(Fixtures\LoggerInterface::class)]);
        $this->failOnce($container, Fixtures\FileLogger::class, fn (Container $c) => $c->get('audit'));

        $this->getFails($container, Fixtures\FileLogger::class);
        $audit = $container->get('audit');

        $this->assertIsArray($audit);
        $this->assertSame($container->get(Fixtures\FileLogger::class), $audit[0]);
        $this->assertSame($container->get(Fixtures\LoggerInterface::class), $audit[0]);
    }

    public function testEntryTheHookCreatedForItselfIsUndoneAsWell(): void
    {
        // Not tied to the entry, but created while the hook ran: it goes, and is created anew on the next get()
        Fixtures\CountingLogger::$created = 0;
        $container = new Container();
        $this->failOnce($container, Fixtures\TestService::class, fn (Container $c) => $c->get(Fixtures\CountingLogger::class));

        $this->getFails($container, Fixtures\TestService::class);
        $container->get(Fixtures\CountingLogger::class);

        $this->assertSame(2, Fixtures\CountingLogger::$created);
    }

    public function testEntryAndBindingCreatedBeforeStay(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $service = $container->get(Fixtures\ServiceInterface::class);
        $this->failOnce($container, Fixtures\TestService::class, fn (Container $c) => null);

        $this->getFails($container, Fixtures\TestService::class);

        $this->assertSame($service, $container->get(Fixtures\ServiceInterface::class));
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot redefine '" . Fixtures\ServiceInterface::class . "': the entry has been created.");
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\AlternativeService::class);
    }

    public function testTypeLockOfAnEntryCreatedBeforeStays(): void
    {
        // Both entries get the default for ServiceInterface; only the second one is undone
        $container = new Container();
        $container->get(Fixtures\ServiceWithOptionalInterface::class);
        $this->failOnce($container, Fixtures\ServiceWithTwoOptionalsOfOneType::class, fn (Container $c) => null);

        $this->getFails($container, Fixtures\ServiceWithTwoOptionalsOfOneType::class);

        try {
            $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot define '" . Fixtures\ServiceInterface::class . "': '" . Fixtures\ServiceWithOptionalInterface::class
                . "' has been created with the default value in its place.",
                $e->getMessage()
            );
        }

        // LoggerInterface only had the default in the entry that was undone: it is open again
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $this->assertInstanceOf(
            Fixtures\FileLogger::class,
            $container->get(Fixtures\ServiceWithTwoOptionalsOfOneType::class)->logger
        );
    }
}
