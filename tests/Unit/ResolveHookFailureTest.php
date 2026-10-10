<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;

/**
 * An entry whose resolve hook throws is not kept: it never passed the hook. The next get() creates it anew.
 */
class ResolveHookFailureTest extends TestCase
{
    use ResolveHookFailure;

    /**
     * A resolve hook that throws the first time it sees $id and lets everything else pass.
     *
     * @param list<mixed> $seen Collects the instances the hook was given for $id
     */
    private function failOnce(Container $container, string $id, ?callable $before = null, array &$seen = []): void
    {
        $failed = false;
        $container->on('resolve', function (array $data) use ($container, $id, $before, &$failed, &$seen) {
            if ($data['id'] !== $id) {
                return;
            }
            $seen[] = $data['instance'];
            if (!$failed) {
                $failed = true;
                if ($before !== null) {
                    $before($container);
                }
                throw new \RuntimeException('Hook failed');
            }
        });
    }

    public function testEntryIsCreatedAnewAfterItsResolveHookThrew(): void
    {
        Fixtures\CountingLogger::$created = 0;
        $container = new Container();
        $seen = [];
        $this->failOnce($container, Fixtures\CountingLogger::class, null, $seen);

        $this->getFails($container, Fixtures\CountingLogger::class);
        $logger = $container->get(Fixtures\CountingLogger::class);

        // Constructor and hook ran twice; what get() returns is the instance that passed the hook
        $this->assertSame(2, Fixtures\CountingLogger::$created);
        $this->assertCount(2, $seen);
        $this->assertNotSame($seen[0], $logger);
        $this->assertSame($seen[1], $logger);
    }

    public function testEntryMayBeRedefinedAfterItsResolveHookThrew(): void
    {
        $container = new Container();
        $this->failOnce($container, Fixtures\ConcreteService::class);

        $this->getFails($container, Fixtures\ConcreteService::class);
        $container->set(Fixtures\ConcreteService::class, fn () => new Fixtures\ConcreteService());

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ConcreteService::class));
    }

    public function testBindingTheHookAskedForIsNotKeptEither(): void
    {
        $container = new Container();
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\CountingLogger::class);
        // The hook asks for the interface, which caches it as an entry, and fails afterwards
        $this->failOnce($container, Fixtures\CountingLogger::class, fn (Container $c) => $c->get(Fixtures\LoggerInterface::class));

        $this->getFails($container, Fixtures\LoggerInterface::class, Fixtures\CountingLogger::class);
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\LoggerInterface::class));
    }

    public function testDefaultsOfAnEntryThatWasNotKeptAreNotInUse(): void
    {
        $container = new Container();
        $this->failOnce($container, Fixtures\ServiceWithOptionalInterface::class);

        $this->getFails($container, Fixtures\ServiceWithOptionalInterface::class);
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ServiceWithOptionalInterface::class)->service
        );
    }

    public function testDefaultsOfTwoParametersOfOneTypeAreUndoneOnce(): void
    {
        // Defaults for ServiceInterface, ServiceInterface and LoggerInterface: each type counts once
        $container = new Container();
        $this->failOnce($container, Fixtures\ServiceWithTwoOptionalsOfOneType::class);

        // An application that turns warnings into exceptions must still see the hook's exception
        set_error_handler(static fn (int $level, string $message) => throw new \ErrorException($message, 0, $level));
        try {
            $this->getFails($container, Fixtures\ServiceWithTwoOptionalsOfOneType::class);
        } finally {
            restore_error_handler();
        }
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);

        $service = $container->get(Fixtures\ServiceWithTwoOptionalsOfOneType::class);
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $service->first);
        $this->assertSame($service->first, $service->second);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $service->logger);
    }

    public function testOtherEntriesWithTheSameValueAreKept(): void
    {
        // Two factories return the same value: only the entry whose hook threw goes
        $container = new Container();
        $calls = ['a' => 0, 'b' => 0];
        $container->set('a', function () use (&$calls) {
            $calls['a']++;

            return 1;
        });
        $container->set('b', function () use (&$calls) {
            $calls['b']++;

            return 1;
        });
        $this->assertSame(1, $container->get('a'));
        $this->failOnce($container, 'b');

        $this->getFails($container, 'b');

        $this->assertSame(1, $container->get('a'));
        $this->assertSame(1, $container->get('b'));
        $this->assertSame(['a' => 1, 'b' => 2], $calls);
    }
}
