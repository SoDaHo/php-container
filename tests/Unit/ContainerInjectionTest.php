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
    private static function autowired(string $class): string
    {
        return "Cannot autowire '$class': it is a container, and autowiring would create a new, empty one. "
            . "Register the container for it: set(\\$class::class, fn (Container \$c) => \$c).";
    }

    private static function notRegistered(string $class, string $parameter): string
    {
        return "Cannot resolve parameter '$parameter' in class '$class': '" . ContainerInterface::class
            . "' is a container, which is not autowired. Register it: set(\\" . ContainerInterface::class . '::class, fn (Container $c) => $c).';
    }

    /**
     * @return array<string, array{callable(Container): mixed, string, string}>
     */
    public static function containersThatWouldBeAutowired(): array
    {
        $nothing = fn (Container $c) => null;
        $bound = fn (Container $c) => $c->bind(ContainerInterface::class, Container::class);

        return [
            'get() of the class' => [$nothing, Container::class, self::autowired(Container::class)],
            'parameter of the class' => [$nothing, Fixtures\ServiceNeedingContainer::class, self::autowired(Container::class)],
            'parameter of the interface' => [$nothing, Fixtures\ServiceNeedingPsrContainer::class, self::notRegistered(Fixtures\ServiceNeedingPsrContainer::class, 'container')],
            'optional parameter of the interface' => [$nothing, Fixtures\ServiceWithOptionalPsrContainer::class, self::notRegistered(Fixtures\ServiceWithOptionalPsrContainer::class, 'container')],
            'interface bound to the class' => [$bound, Fixtures\ServiceNeedingPsrContainer::class, self::autowired(Container::class)],
        ];
    }

    /**
     * @param callable(Container): mixed $register
     */
    #[DataProvider('containersThatWouldBeAutowired')]
    public function testContainerIsNotAutowired(callable $register, string $id, string $message): void
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
        $this->assertCount(1, $reported);
        $this->assertContains($reported[0], [Container::class, ContainerInterface::class]);
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
}
