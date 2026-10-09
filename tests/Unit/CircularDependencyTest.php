<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * Cycles through constructors, bindings and factories throw, with the exact chain.
 */
class CircularDependencyTest extends TestCase
{
    public function testSelfDependencyIsDetected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency detected');

        $container->get(Fixtures\SelfDependent::class);
    }

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
            $previous = $e->getPrevious();
            $this->assertInstanceOf(ContainerException::class, $previous);
            $this->assertSame("Error while creating service 'b'.", $previous->getMessage());
            $this->assertSame('Circular dependency detected: a -> b -> a', $previous->getPrevious()?->getMessage());
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
}
