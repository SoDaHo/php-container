<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * has(): true for the ids get() can return and for a cycle of bindings, without creating anything.
 */
class HasTest extends TestCase
{
    public function testHasReturnsTrueForDefinition(): void
    {
        $container = new Container();
        $container->set('custom', fn () => 'value');

        $this->assertTrue($container->has('custom'));
    }

    public function testHasReturnsTrueForExistingClass(): void
    {
        $container = new Container();

        $this->assertTrue($container->has(\stdClass::class));
    }

    public function testHasCreatesNothing(): void
    {
        Fixtures\CountingLogger::$created = 0;
        $container = new Container();
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\CountingLogger::class);

        $this->assertTrue($container->has(Fixtures\CountingLogger::class));
        $this->assertTrue($container->has(Fixtures\LoggerInterface::class));
        $this->assertSame(0, Fixtures\CountingLogger::$created);

        // Nor does it make the entry exist: it can still be redefined
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\LoggerInterface::class));
    }

    public function testHasReturnsFalseForNonExistent(): void
    {
        $container = new Container();

        $this->assertFalse($container->has('non.existent.service'));
    }

    public function testHasReturnsFalseForAbstractClass(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Fixtures\AbstractService::class));
    }

    public function testHasReturnsFalseForInterfaceWithoutBinding(): void
    {
        $container = new Container();

        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));
    }

    public function testHasReturnsTrueForBoundInterface(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertTrue($container->has(Fixtures\ServiceInterface::class));
    }

    public function testHasReturnsFalseForAliasToMissingClass(): void
    {
        $container = new Container();
        // @phpstan-ignore argument.type (a typo in the class name, the case the README describes)
        $container->bind(Fixtures\ServiceInterface::class, 'Missing\Implementation');

        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));

        $this->expectException(NotFoundException::class);
        $container->get(Fixtures\ServiceInterface::class);
    }

    public function testHasFollowsAliasChain(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $this->assertFalse($container->has(Fixtures\FirstInterface::class));

        $container->bind(Fixtures\SecondInterface::class, Fixtures\ConcreteService::class);
        $this->assertTrue($container->has(Fixtures\FirstInterface::class));
    }

    public function testHasReturnsTrueForAliasToDefinition(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\SecondInterface::class);
        $container->set(Fixtures\SecondInterface::class, fn () => new Fixtures\ConcreteService());

        $this->assertTrue($container->has(Fixtures\ServiceInterface::class));
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testHasReturnsTrueForAnIdOnACycleOfBindings(): void
    {
        // Known, but broken: get() throws a ContainerException, which PSR-11 allows only when has() is true
        $container = new Container();
        // @phpstan-ignore argument.type (names that are no classes: what a cycle of bindings is made of)
        $container->bind('Missing\First', 'Missing\Second');
        // @phpstan-ignore argument.type (names that are no classes: what a cycle of bindings is made of)
        $container->bind('Missing\Second', 'Missing\First');

        $this->assertTrue($container->has('Missing\First'));
        $this->assertTrue($container->has('Missing\Second'));

        try {
            $container->get('Missing\First');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(ContainerException::class, $e::class);
            $this->assertSame('Circular dependency detected: Missing\First -> Missing\Second -> Missing\First', $e->getMessage());
        }
    }

    public function testHasReturnsTrueForInstanceBehindAlias(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);
        $container->set(Fixtures\SecondInterface::class, fn () => 'value');
        $container->get(Fixtures\SecondInterface::class);

        $this->assertTrue($container->has(Fixtures\FirstInterface::class));
    }
}
