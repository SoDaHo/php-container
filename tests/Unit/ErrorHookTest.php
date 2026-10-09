<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * The error hook: fired once where get() fails, and what happens when it throws or uses the container.
 */
class ErrorHookTest extends TestCase
{
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

    /**
     * @return array<string, array{string, string, class-string<\Throwable>, string}>
     */
    public static function failingIds(): array
    {
        return [
            'class not found' => ['Missing\Service', 'Missing\Service', NotFoundException::class, 'not found'],
            'unbound interface' => [Fixtures\ServiceInterface::class, Fixtures\ServiceInterface::class, NotFoundException::class, 'not found'],
            'abstract class' => [Fixtures\AbstractService::class, Fixtures\AbstractService::class, ContainerException::class, 'not instantiable'],
            'circular' => [Fixtures\CircularA::class, Fixtures\CircularA::class, ContainerException::class, 'Circular dependency detected'],
            'variadic' => [Fixtures\ServiceWithVariadic::class, Fixtures\ServiceWithVariadic::class, ContainerException::class, 'variadic parameter'],
            'no type' => [Fixtures\ServiceWithNoTypeNoDefault::class, Fixtures\ServiceWithNoTypeNoDefault::class, ContainerException::class, 'No type hint'],
            'union type' => [Fixtures\ServiceWithUnionNoDefault::class, Fixtures\ServiceWithUnionNoDefault::class, ContainerException::class, 'No type hint'],
            'primitive' => [Fixtures\ServiceWithConfig::class, Fixtures\ServiceWithConfig::class, ContainerException::class, 'primitive parameter'],
            // Reported where it happens: for the dependency that is missing, not for the class that needs it
            'missing dependency' => [Fixtures\ControllerWithInterface::class, Fixtures\ServiceInterface::class, NotFoundException::class, 'not found'],
            'failing nested dependency' => [Fixtures\ServiceWithOptionalBroken::class, Fixtures\ServiceWithConfig::class, ContainerException::class, 'primitive parameter'],
        ];
    }

    /**
     * @param class-string<\Throwable> $reportedClass
     */
    #[DataProvider('failingIds')]
    public function testErrorHookIsFiredOnceWhereTheFailureHappens(string $id, string $reportedId, string $reportedClass, string $reportedMessage): void
    {
        $container = new Container();
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException) {
            // Expected
        }

        $this->assertCount(1, $firedErrors);
        $this->assertSame($reportedId, $firedErrors[0]['id']);
        $this->assertSame($reportedClass, $firedErrors[0]['exception']::class);
        $this->assertStringContainsString($reportedMessage, $firedErrors[0]['exception']->getMessage());
    }

    public function testErrorHookIsFiredForACycleOfBindings(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $container->bind(Fixtures\SecondInterface::class, Fixtures\FirstInterface::class);
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get(Fixtures\FirstInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertCount(1, $firedErrors);
            $this->assertSame(Fixtures\FirstInterface::class, $firedErrors[0]['id']);
            $this->assertSame($e, $firedErrors[0]['exception']);
        }
    }

    public function testErrorHookNamesTheMissingClassBehindABinding(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, 'Missing\Implementation');
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertStringStartsWith("Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter '", $e->getMessage());
            $this->assertCount(1, $firedErrors);
            $this->assertSame('Missing\Implementation', $firedErrors[0]['id']);
            $this->assertSame($e->getPrevious(), $firedErrors[0]['exception']);
            $this->assertSame("Class or service 'Missing\Implementation' not found.", $firedErrors[0]['exception']->getMessage());
        }
    }

    public function testErrorHookIsNotFiredWhenAnOptionalDependencyFallsBackToItsDefault(): void
    {
        $container = new Container();
        $fired = 0;
        $container->on('error', function () use (&$fired) {
            $fired++;
        });

        $container->get(Fixtures\ServiceWithOptionalDep::class);
        $container->get(Fixtures\ServiceWithEnumDefault::class);
        $container->get(Fixtures\ServiceWithOptionalAbstract::class);

        $this->assertSame(0, $fired);
    }

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
            $this->assertSame("Error while creating service 'outer'.", $e->getMessage());
        }

        $this->assertSame(['inner', 'outer'], $ids);
    }

    public function testErrorHookThatUsesTheContainerIsNotCalledForItsOwnFailure(): void
    {
        // The logger the hook asks for is what cannot be built
        $container = new Container();
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\LoggerNeedingTransport::class);
        $calls = 0;
        $failedInsideHook = null;
        $container->on('error', function () use ($container, &$calls, &$failedInsideHook) {
            $calls++;
            try {
                $container->get(Fixtures\LoggerInterface::class);
            } catch (ContainerException $e) {
                $failedInsideHook = $e->getMessage();
            }
        });

        try {
            $container->get(Fixtures\NeedsLogger::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot resolve dependency '" . Fixtures\NonExistentInterface::class . "' for parameter 'transport' in class '" . Fixtures\LoggerNeedingTransport::class . "'.",
                $e->getMessage()
            );
        }

        $this->assertSame(1, $calls);
        $this->assertStringStartsWith('Circular dependency detected: ', (string) $failedInsideHook);

        // The guard is released afterwards: the next failure is reported again
        try {
            $container->get('Missing\Service');
        } catch (NotFoundException) {
            // Expected
        }
        $this->assertSame(2, $calls);
    }

    public function testErrorHookExceptionForAMissingDependencyKeepsTheWrapperIfItIsANotFoundException(): void
    {
        // What get() says stays true (the dependency cannot be resolved); the hook's exception is the cause
        $container = new Container();
        $thrown = new NotFoundException('Thrown by the hook');
        $container->on('error', fn () => throw $thrown);

        try {
            // ControllerWithInterface needs ServiceInterface, which is not bound
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertStringStartsWith("Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "'", $e->getMessage());
            $this->assertSame($thrown, $e->getPrevious());
        }
    }

    public function testErrorHookExceptionForAMissingDependencyPassesIfItIsAnythingElse(): void
    {
        $container = new Container();
        $thrown = new \RuntimeException('log sink unavailable');
        $container->on('error', fn () => throw $thrown);

        try {
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }
    }

    public function testErrorHookExceptionForAMissingDependencyPassesEvenIfItIsAContainerException(): void
    {
        $container = new Container();
        $thrown = new ContainerException('Thrown by the hook');
        $container->on('error', fn () => throw $thrown);

        try {
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame($thrown, $e);
        }
    }

    public function testErrorHookIsCalledAgainAfterItThrew(): void
    {
        $container = new Container();
        $calls = 0;
        $container->on('error', function () use (&$calls) {
            $calls++;
            throw new \RuntimeException('log sink unavailable');
        });

        foreach ([1, 2] as $attempt) {
            try {
                $container->get('Missing\Service');
                $this->fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                $this->assertSame('log sink unavailable', $e->getMessage());
            }
        }

        $this->assertSame(2, $calls);
    }

    public function testErrorHookExceptionReplacesTheContainerException(): void
    {
        $container = new Container();
        $container->on('error', fn () => throw new \RuntimeException('log sink unavailable'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('log sink unavailable');

        $container->get('Missing\Service');
    }
}
