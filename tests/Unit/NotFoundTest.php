<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\NotFoundExceptionInterface;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * PSR-11: get() throws a NotFoundExceptionInterface for an id has() is false for, and says why.
 */
class NotFoundTest extends TestCase
{
    /**
     * @return array<string, array{callable(Container): mixed, string, string}>
     */
    public static function idsTheContainerCannotCreate(): array
    {
        $nothing = fn (Container $c) => null;

        return [
            'class that does not exist' => [$nothing, 'Missing\Service', "Class or service 'Missing\Service' not found."],
            'interface without binding' => [
                $nothing,
                Fixtures\ServiceInterface::class,
                "Interface '" . Fixtures\ServiceInterface::class . "' not found: no implementation is bound to it.",
            ],
            'abstract class' => [
                $nothing,
                Fixtures\AbstractService::class,
                "Class '" . Fixtures\AbstractService::class . "' is not instantiable: it is abstract.",
            ],
            'enum' => [$nothing, Fixtures\Mode::class, "Class '" . Fixtures\Mode::class . "' is not instantiable: it is an enum."],
            'private constructor' => [
                $nothing,
                Fixtures\ServiceWithPrivateConstructor::class,
                "Class '" . Fixtures\ServiceWithPrivateConstructor::class . "' is not instantiable: its constructor is not public.",
            ],
            'interface bound to an abstract class' => [
                fn (Container $c) => $c->bind(Fixtures\ServiceInterface::class, Fixtures\AbstractService::class),
                Fixtures\ServiceInterface::class,
                "Class '" . Fixtures\AbstractService::class . "' is not instantiable: it is abstract.",
            ],
        ];
    }

    /**
     * @param callable(Container): mixed $register
     */
    #[DataProvider('idsTheContainerCannotCreate')]
    public function testGetThrowsNotFoundForAnIdHasIsFalseFor(callable $register, string $id, string $message): void
    {
        $container = new Container();
        $register($container);

        $this->assertFalse($container->has($id));

        try {
            $container->get($id);
            $this->fail('Expected NotFoundException');
        } catch (NotFoundExceptionInterface $e) {
            $this->assertInstanceOf(NotFoundException::class, $e);
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function testDependencyThatHasIsFalseForIsAnErrorOfTheClassThatNeedsIt(): void
    {
        // has() is true for the class that needs it, so its get() must not throw a NotFoundException
        $container = new Container();
        $this->assertTrue($container->has(Fixtures\ServiceNeedingAbstract::class));

        try {
            $container->get(Fixtures\ServiceNeedingAbstract::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertSame(
                "Cannot resolve dependency '" . Fixtures\AbstractService::class . "' for parameter 'service' in class '"
                . Fixtures\ServiceNeedingAbstract::class . "'.",
                $e->getMessage()
            );
            $this->assertInstanceOf(NotFoundException::class, $e->getPrevious());
        }
    }
}
