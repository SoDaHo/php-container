<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * Values the container gives up on are destroyed where it catches what their destructors throw: the exception
 * get() throws for the failure stays on top, the destructor's failure goes to its debug message.
 */
class DestructorTest extends TestCase
{
    protected function tearDown(): void
    {
        Fixtures\ThrowingDestructor::$armed = false;
        Fixtures\DestructorCallback::$run = null;
    }

    /**
     * A resolve hook on TestService that creates $created and throws.
     */
    private function hookThatCreatesAndThrows(Container $container, string $created): void
    {
        $container->on('resolve', function (array $data) use ($container, $created) {
            if ($data['id'] === Fixtures\TestService::class) {
                $container->get($created);
                throw new \RuntimeException('Hook failed');
            }
        });
    }

    private function assertHookFailureOnTop(Container $container, string $debugPart): void
    {
        try {
            $container->get(Fixtures\TestService::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Resolve hook failed for '" . Fixtures\TestService::class . "'.", $e->getMessage());
            $this->assertSame('Hook failed', $e->getPrevious()?->getMessage());
            $this->assertStringContainsString('; A destructor threw while the container discarded it: ', (string) $e->getDebugMessage());
            $this->assertStringContainsString($debugPart, (string) $e->getDebugMessage());
        }
    }

    public function testEntryWhoseHookThrowsIsDestroyedDuringTheRollback(): void
    {
        // Without arguments in traces, nothing but the container holds the entry: its destructor runs in the rollback
        $ignoreArgs = ini_set('zend.exception_ignore_args', '1');
        try {
            $container = new Container();
            $container->on('resolve', function (array $data) {
                if ($data['id'] === Fixtures\ThrowingDestructor::class) {
                    throw new \RuntimeException('Hook failed');
                }
            });
            Fixtures\ThrowingDestructor::$armed = true;

            try {
                $container->get(Fixtures\ThrowingDestructor::class);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertSame("Resolve hook failed for '" . Fixtures\ThrowingDestructor::class . "'.", $e->getMessage());
                $this->assertStringContainsString('; A destructor threw while the container discarded it: LogicException in ', (string) $e->getDebugMessage());
            }
            $this->assertFalse(Fixtures\ThrowingDestructor::$armed, 'The destructor ran during the rollback');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
        }
    }

    public function testThrowingDestructorDuringTheRollbackLeavesTheHookFailureOnTop(): void
    {
        $container = new Container();
        $this->hookThatCreatesAndThrows($container, Fixtures\ThrowingDestructor::class);
        Fixtures\ThrowingDestructor::$armed = true;

        $this->assertHookFailureOnTop($container, 'LogicException in ');
        $this->assertFalse(Fixtures\ThrowingDestructor::$armed, 'The destructor ran during the rollback');
    }

    /**
     * @return array<string, array{callable(Container): mixed, string}>
     */
    public static function destructorsThatUseTheContainer(): array
    {
        return [
            'set()' => [fn (Container $c) => $c->set('late', fn () => 1), "Cannot define 'late' while get() is running"],
            'get() of a missing id' => [fn (Container $c) => $c->get('Missing\Cleanup'), "Class or service 'Missing\Cleanup' not found."],
        ];
    }

    /**
     * @param callable(Container): mixed $use
     */
    #[DataProvider('destructorsThatUseTheContainer')]
    public function testDestructorThatUsesTheContainerDuringTheRollbackLeavesTheHookFailureOnTop(callable $use, string $debugPart): void
    {
        $container = new Container();
        $this->hookThatCreatesAndThrows($container, Fixtures\DestructorCallback::class);
        Fixtures\DestructorCallback::$run = fn () => $use($container);

        $this->assertHookFailureOnTop($container, $debugPart);
    }

    public function testThrowingDestructorOfAFactoryResultOfTheWrongTypeLeavesTheTypeErrorOnTop(): void
    {
        $container = new Container();
        $container->set(Fixtures\ServiceInterface::class, fn () => new Fixtures\ThrowingDestructor());
        Fixtures\ThrowingDestructor::$armed = true;

        try {
            $container->get(Fixtures\ServiceInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertSame(
                "Error while creating service '" . Fixtures\ServiceInterface::class . "': the factory returned "
                . Fixtures\ThrowingDestructor::class . ', not an instance of it.',
                $e->getMessage()
            );
            $this->assertStringStartsWith('A destructor threw while the container discarded it: LogicException in ', (string) $e->getDebugMessage());
            $this->assertStringEndsWith(': Destructor failed', (string) $e->getDebugMessage());
        }
    }
}
