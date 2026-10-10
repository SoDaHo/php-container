<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * A container is never autowired: that would be a new, empty one, past every factory, binding and hook of the
 * container that was asked. Registered under a type, it is passed like any other entry.
 */
class ContainerInjectionTest extends TestCase
{
    private static function autowired(string $class, string $registration): string
    {
        return "Cannot autowire '$class': it is a container, and autowiring would create a new, empty one. $registration";
    }

    private static function notRegistered(string $class, string $parameter): string
    {
        return "Cannot resolve parameter '$parameter' in class '$class': '" . ContainerInterface::class
            . "' is a container, which is not autowired. " . self::itself(ContainerInterface::class);
    }

    private static function itself(string $type): string
    {
        return "Register the container for it: set(\\$type::class, fn (Container \$c) => \$c).";
    }

    /**
     * @return array<string, array{callable(Container): mixed, string, string, string}>
     */
    public static function containersThatWouldBeAutowired(): array
    {
        $nothing = fn (Container $c) => null;
        $bound = fn (Container $c) => $c->bind(ContainerInterface::class, Container::class);
        $foreign = Fixtures\ForeignContainer::class;

        return [
            'get() of the class' => [$nothing, Container::class, self::autowired(Container::class, self::itself(Container::class)), Container::class],
            'parameter of the class' => [
                $nothing,
                Fixtures\ServiceNeedingContainer::class,
                self::autowired(Container::class, self::itself(Container::class)),
                Container::class,
            ],
            'parameter of the interface' => [
                $nothing,
                Fixtures\ServiceNeedingPsrContainer::class,
                self::notRegistered(Fixtures\ServiceNeedingPsrContainer::class, 'container'),
                ContainerInterface::class,
            ],
            'optional parameter of the interface' => [
                $nothing,
                Fixtures\ServiceWithOptionalPsrContainer::class,
                self::notRegistered(Fixtures\ServiceWithOptionalPsrContainer::class, 'container'),
                ContainerInterface::class,
            ],
            'interface bound to the class' => [
                $bound,
                Fixtures\ServiceNeedingPsrContainer::class,
                self::autowired(Container::class, self::itself(Container::class)),
                Container::class,
            ],
            // This container is no ForeignContainer: handing it over would fail, so the message asks for a factory
            'container of another kind' => [
                $nothing,
                Fixtures\ServiceNeedingForeignContainer::class,
                self::autowired($foreign, "Register a factory that creates it: set(\\$foreign::class, ...)."),
                $foreign,
            ],
        ];
    }

    /**
     * @param callable(Container): mixed $register
     */
    #[DataProvider('containersThatWouldBeAutowired')]
    public function testContainerIsNotAutowired(callable $register, string $id, string $message, string $reportedId): void
    {
        $container = new Container();
        $register($container);
        $reported = [];
        $container->on('error', function (array $data) use (&$reported) {
            $reported[] = $data['id'];
        });

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertNotInstanceOf(NotFoundException::class, $e);
            $this->assertSame($message, $e->getMessage());
        }

        // Reported once, for the container that was asked for, not for the class that needs it
        $this->assertSame([$reportedId], $reported);
    }

    public function testContainerParameterWithABrokenBindingNamesTheBinding(): void
    {
        // Something is registered: the failure is the binding's, not a missing registration
        $container = new Container();
        // @phpstan-ignore argument.type (a typo in the class name, the case the README describes)
        $container->bind(ContainerInterface::class, 'Missing\Typo');

        try {
            $container->get(Fixtures\ServiceNeedingPsrContainer::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot resolve dependency '" . ContainerInterface::class . "' for parameter 'container' in class '"
                . Fixtures\ServiceNeedingPsrContainer::class . "'.",
                $e->getMessage()
            );
            $this->assertSame("Class or service 'Missing\Typo' not found.", $e->getPrevious()?->getMessage());
        }
    }

    public function testContainerRegisteredForItsInterfaceIsPassed(): void
    {
        $container = new Container();
        $container->set(ContainerInterface::class, fn (Container $c) => $c);

        $this->assertSame($container, $container->get(Fixtures\ServiceNeedingPsrContainer::class)->container);
        $this->assertSame($container, $container->get(Fixtures\ServiceWithOptionalPsrContainer::class)->container);
    }

    public function testContainerRegisteredForItsClassIsPassed(): void
    {
        $container = new Container();
        $container->set(Container::class, fn (Container $c) => $c);

        $this->assertSame($container, $container->get(Fixtures\ServiceNeedingContainer::class)->container);
    }

    public function testContainerOfAnotherKindWithAFactoryIsPassed(): void
    {
        $foreign = new Fixtures\ForeignContainer();
        $container = new Container();
        $container->set(Fixtures\ForeignContainer::class, fn () => $foreign);

        $this->assertSame($foreign, $container->get(Fixtures\ServiceNeedingForeignContainer::class)->container);
    }
}
