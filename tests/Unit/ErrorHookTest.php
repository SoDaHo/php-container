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
            'unbound interface' => [Fixtures\ServiceInterface::class, Fixtures\ServiceInterface::class, NotFoundException::class, 'no implementation is bound'],
            'abstract class' => [Fixtures\AbstractService::class, Fixtures\AbstractService::class, NotFoundException::class, 'not instantiable: it is abstract'],
            'circular' => [Fixtures\CircularA::class, Fixtures\CircularA::class, ContainerException::class, 'Circular dependency detected'],
            'variadic' => [Fixtures\ServiceWithVariadic::class, Fixtures\ServiceWithVariadic::class, ContainerException::class, 'variadic parameter'],
            'no type' => [Fixtures\ServiceWithNoTypeNoDefault::class, Fixtures\ServiceWithNoTypeNoDefault::class, ContainerException::class, 'it has no type'],
            'union type' => [Fixtures\ServiceWithUnionNoDefault::class, Fixtures\ServiceWithUnionNoDefault::class, ContainerException::class, 'it has a union type'],
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
        $exception = $firedErrors[0]['exception'];
        $this->assertInstanceOf(\Throwable::class, $exception);
        $this->assertSame($reportedClass, $exception::class);
        $this->assertStringContainsString($reportedMessage, $exception->getMessage());
    }

    public function testErrorHookIsFiredForACycleOfBindings(): void
    {
        $container = new Container();
        // @phpstan-ignore argument.type (names that are no classes: what a cycle of bindings is made of)
        $container->bind('Missing\First', 'Missing\Second');
        // @phpstan-ignore argument.type (names that are no classes: what a cycle of bindings is made of)
        $container->bind('Missing\Second', 'Missing\First');
        $firedErrors = [];
        $container->on('error', function (array $data) use (&$firedErrors) {
            $firedErrors[] = $data;
        });

        try {
            $container->get('Missing\First');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertCount(1, $firedErrors);
            $this->assertSame('Missing\First', $firedErrors[0]['id']);
            $this->assertSame($e, $firedErrors[0]['exception']);
        }
    }

    public function testErrorHookNamesTheMissingClassBehindABinding(): void
    {
        $container = new Container();
        // @phpstan-ignore argument.type (a typo in the class name, the case the README describes)
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
            $this->assertSame("Class or service 'Missing\Implementation' not found.", $e->getPrevious()?->getMessage());
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
}
