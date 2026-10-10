<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * A union of one class with null or false is that class, like ?T. Every other union gets its default or throws.
 */
class UnionTypeTest extends TestCase
{
    public function testClassOrFalseUsesTheBinding(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceWithFalseUnion::class)->service);
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceWithFalseOrNullUnion::class)->service);
    }

    public function testClassOrFalseGetsTheDefaultWhenTheContainerCannotProvideTheClass(): void
    {
        $container = new Container();

        $this->assertFalse($container->get(Fixtures\ServiceWithFalseUnion::class)->service);
        $this->assertNull($container->get(Fixtures\ServiceWithFalseOrNullUnion::class)->service);

        // Like for ?T: the type is taken, a binding for it now would never reach the entries
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot define '" . Fixtures\ServiceInterface::class . "'");
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
    }

    public function testClassOrFalseThatCannotBeBuiltIsAnError(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve primitive parameter 'apiKey'");

        $container->get(Fixtures\ServiceWithUnbuildableFalseUnion::class);
    }

    public function testClassOrFalseWithoutDefaultIsRequiredLikeTheClass(): void
    {
        $container = new Container();

        $this->assertSame(
            $container->get(Fixtures\TestService::class),
            $container->get(Fixtures\ServiceWithFalseUnionNoDefault::class)->service
        );
    }

    public function testOtherUnionStillGetsItsDefault(): void
    {
        $container = new Container();

        $this->assertSame('default', $container->get(Fixtures\ServiceWithClassOrStringUnion::class)->value);
    }
}
