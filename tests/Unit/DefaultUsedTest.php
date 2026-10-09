<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * A default given to a created entry stands for its type: a definition for that type would never reach the
 * entry, so set() and bind() throw for it, like for an entry that exists.
 */
class DefaultUsedTest extends TestCase
{
    /**
     * @return array<string, array{callable(Container): mixed}>
     */
    public static function definitions(): array
    {
        return [
            'bind()' => [fn (Container $c) => $c->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class)],
            'set()' => [fn (Container $c) => $c->set(Fixtures\ServiceInterface::class, fn () => new Fixtures\ConcreteService())],
        ];
    }

    /**
     * @param callable(Container): mixed $define
     */
    #[DataProvider('definitions')]
    public function testTypeCannotBeDefinedOnceAnEntryGotTheDefaultForIt(callable $define): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithOptionalInterface::class);
        $this->assertNull($service->service);

        try {
            $define($container);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot define '" . Fixtures\ServiceInterface::class . "': '" . Fixtures\ServiceWithOptionalInterface::class
                . "' has been created with the default value in its place.",
                $e->getMessage()
            );
        }

        // Nothing changed: the entry keeps its default, the type stays unknown
        $this->assertSame($service, $container->get(Fixtures\ServiceWithOptionalInterface::class));
        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));
    }

    /**
     * @param callable(Container): mixed $define
     */
    #[DataProvider('definitions')]
    public function testTypeMayBeDefinedWhenTheEntryThatWouldHaveGotTheDefaultFailed(callable $define): void
    {
        $container = new Container();

        try {
            $container->get(Fixtures\ServiceWithOptionalInterfaceThatThrows::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException) {
            // Expected: nothing was created, so no default is in use
        }

        $define($container);

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ServiceWithOptionalInterface::class)->service
        );
    }
}
