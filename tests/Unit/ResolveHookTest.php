<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * The resolve hook: fired once per created entry, and what happens when it throws.
 */
class ResolveHookTest extends TestCase
{
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

    public function testResolveHookIsFiredForDependenciesFirst(): void
    {
        $container = new Container();
        $ids = [];
        $container->on('resolve', function (array $data) use (&$ids) {
            $ids[] = $data['id'];
        });

        $container->get(Fixtures\DeepController::class);

        $this->assertSame([Fixtures\TestService::class, Fixtures\TestController::class, Fixtures\DeepController::class], $ids);
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

    public function testHookExceptionLeavesGetAsAContainerException(): void
    {
        $container = new Container();
        $thrown = new \RuntimeException('Hook failed: secret-token');
        $container->on('resolve', fn () => throw $thrown);

        try {
            $container->get(\stdClass::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            // The message names the entry only; the hook's text is in getPrevious() and the debug message
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertSame("Resolve hook failed for 'stdClass'.", $e->getMessage());
            $this->assertSame($thrown, $e->getPrevious());
            $this->assertStringEndsWith(': Hook failed: secret-token', (string) $e->getDebugMessage());
        }
    }

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
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Resolve hook failed for '$id'.", $e->getMessage());
            $this->assertSame('Hook failed', $e->getPrevious()?->getMessage());
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

    /**
     * @return array<string, array{callable(Container): mixed, class-string, string}>
     */
    public static function dependenciesWithAFailingResolveHook(): array
    {
        return [
            'concrete class' => [
                fn (Container $c) => null,
                Fixtures\TestController::class,
                Fixtures\TestService::class,
            ],
            'bound interface' => [
                fn (Container $c) => $c->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class),
                Fixtures\ControllerWithInterface::class,
                Fixtures\ConcreteService::class,
            ],
            'interface with a factory' => [
                fn (Container $c) => $c->set(Fixtures\ServiceInterface::class, fn () => new Fixtures\ConcreteService()),
                Fixtures\ControllerWithInterface::class,
                Fixtures\ServiceInterface::class,
            ],
            'interface bound to an id with a factory' => [
                function (Container $c): void {
                    $c->bind(Fixtures\ServiceInterface::class, Fixtures\SecondInterface::class);
                    $c->set(Fixtures\SecondInterface::class, fn () => new Fixtures\ConcreteService());
                },
                Fixtures\ControllerWithInterface::class,
                Fixtures\SecondInterface::class,
            ],
        ];
    }

    /**
     * @param callable(Container): mixed $register
     * @param class-string $id
     */
    #[DataProvider('dependenciesWithAFailingResolveHook')]
    public function testNotFoundExceptionOfAResolveHookIsWrapped(callable $register, string $id, string $announced): void
    {
        // has() is true for the entry, so get() must not throw a NotFoundException for it, nor for what needs it
        $container = new Container();
        $register($container);
        $container->on('resolve', function (array $data) use ($container, $announced) {
            if ($data['id'] === $announced) {
                $container->get('Missing\Thing');
            }
        });

        foreach ([$announced, $id] as $asked) {
            $this->assertTrue($container->has($asked));
            try {
                $container->get($asked);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertSame(ContainerException::class, $e::class);
                $this->assertSame("Resolve hook failed for '$announced'.", $e->getMessage());
                $previous = $e->getPrevious();
                $this->assertInstanceOf(NotFoundException::class, $previous);
                $this->assertSame("Class or service 'Missing\Thing' not found.", $previous->getMessage());
                $this->assertStringEndsWith(": Class or service 'Missing\Thing' not found.", (string) $e->getDebugMessage());
            }
        }
    }
}
