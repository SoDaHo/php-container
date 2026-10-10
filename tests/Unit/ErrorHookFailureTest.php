<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * An error hook that throws (a log sink that is down) does not hide the failure: get() throws an exception of the
 * same class and message, with the failure in getPrevious() and what the hook threw in getDebugMessage().
 */
class ErrorHookFailureTest extends TestCase
{
    /**
     * @return array<string, array{\Throwable}>
     */
    public static function exceptionsAHookThrows(): array
    {
        return [
            'RuntimeException' => [new \RuntimeException('log sink unavailable')],
            'NotFoundException' => [new NotFoundException('Thrown by the hook')],
            'ContainerException' => [new ContainerException('Thrown by the hook')],
        ];
    }

    private function assertHookIsInTheDebugMessage(ContainerException $e, \Throwable $thrown): void
    {
        $this->assertSame(
            'The error hook threw ' . $thrown::class . ' in ' . $thrown->getFile() . ':' . $thrown->getLine() . ': ' . $thrown->getMessage(),
            $e->getDebugMessage()
        );
    }

    #[DataProvider('exceptionsAHookThrows')]
    public function testIdThatDoesNotExistStaysANotFoundException(\Throwable $thrown): void
    {
        $container = new Container();
        $container->on('error', fn () => throw $thrown);

        try {
            $container->get('Missing\Service');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            $this->assertSame(NotFoundException::class, $e::class);
            $this->assertSame("Class or service 'Missing\Service' not found.", $e->getMessage());
            $this->assertHookIsInTheDebugMessage($e, $thrown);
            $previous = $e->getPrevious();
            $this->assertInstanceOf(NotFoundException::class, $previous);
            $this->assertSame("Class or service 'Missing\Service' not found.", $previous->getMessage());
        }
    }

    #[DataProvider('exceptionsAHookThrows')]
    public function testMissingDependencyStaysAnErrorOfTheClassThatNeedsIt(\Throwable $thrown): void
    {
        $container = new Container();
        $container->on('error', fn () => throw $thrown);

        try {
            // ControllerWithInterface needs ServiceInterface, which is not bound
            $container->get(Fixtures\ControllerWithInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertSame(
                "Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter 'service' in class '"
                . Fixtures\ControllerWithInterface::class . "'.",
                $e->getMessage()
            );
            $notFound = $e->getPrevious();
            $this->assertInstanceOf(NotFoundException::class, $notFound);
            $this->assertSame("Interface '" . Fixtures\ServiceInterface::class . "' not found: no implementation is bound to it.", $notFound->getMessage());
            $this->assertHookIsInTheDebugMessage($notFound, $thrown);
        }
    }

    /**
     * @return array<string, array{callable(Container): mixed, string, string}>
     */
    public static function failuresWithACause(): array
    {
        return [
            'factory' => [
                fn (Container $c) => $c->set('broken', fn () => throw new \LogicException('Oops')),
                'broken',
                "Error while creating service 'broken'.",
            ],
            'constructor' => [
                fn (Container $c) => null,
                Fixtures\ServiceThrowsInConstructor::class,
                "Failed to instantiate '" . Fixtures\ServiceThrowsInConstructor::class . "'.",
            ],
        ];
    }

    /**
     * @param callable(Container): mixed $register
     */
    #[DataProvider('failuresWithACause')]
    public function testFailureWithACauseKeepsItsCause(callable $register, string $id, string $message): void
    {
        $container = new Container();
        $register($container);
        $thrown = new \RuntimeException('log sink unavailable');
        $container->on('error', fn () => throw $thrown);

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertSame($message, $e->getMessage());
            $this->assertHookIsInTheDebugMessage($e, $thrown);
            // The exception get() would have thrown without the hook, with the cause behind it
            $failure = $e->getPrevious();
            $this->assertInstanceOf(ContainerException::class, $failure);
            $this->assertSame($message, $failure->getMessage());
            $this->assertInstanceOf(\Throwable::class, $failure->getPrevious());
            $this->assertNotSame($thrown, $failure->getPrevious());
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
                $this->fail('Expected NotFoundException');
            } catch (NotFoundException $e) {
                $this->assertStringEndsWith(': log sink unavailable', (string) $e->getDebugMessage());
            }
        }

        $this->assertSame(2, $calls);
    }
}
