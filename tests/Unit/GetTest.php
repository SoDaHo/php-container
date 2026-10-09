<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * get(), set() and what a factory returns: entries are created once.
 */
class GetTest extends TestCase
{
    public function testGetReturnsSingleton(): void
    {
        $container = new Container();
        $obj1 = $container->get(\stdClass::class);
        $obj2 = $container->get(\stdClass::class);

        $this->assertInstanceOf(\stdClass::class, $obj1);
        $this->assertSame($obj1, $obj2, 'Container should return the same instance (Singleton)');
    }

    public function testManualDefinition(): void
    {
        $container = new Container();
        $container->set('db.host', fn () => 'localhost');

        $this->assertEquals('localhost', $container->get('db.host'));
    }

    public function testFactoryThatReturnsNullRunsOnlyOnce(): void
    {
        $container = new Container();
        $calls = 0;
        $container->set('nothing', function () use (&$calls) {
            $calls++;
            return null;
        });

        $this->assertNull($container->get('nothing'));
        $this->assertNull($container->get('nothing'));
        $this->assertSame(1, $calls);
    }

    public function testManualDefinitionIsSingleton(): void
    {
        $container = new Container();
        $container->set('service', fn () => new \stdClass());

        $obj1 = $container->get('service');
        $obj2 = $container->get('service');

        $this->assertSame($obj1, $obj2);
    }

    public function testGetThrowsNotFoundForNonExistentClass(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get('NonExistentClass');
    }

    public function testFactoryReceivesContainer(): void
    {
        $container = new Container();
        $container->set('dep', fn () => 'dependency-value');
        $container->set('service', fn (Container $c) => 'got: ' . $c->get('dep'));

        $this->assertEquals('got: dependency-value', $container->get('service'));
    }

    public function testFactoryExceptionIsWrapped(): void
    {
        $container = new Container();
        $container->set('broken', fn () => throw new \RuntimeException('Factory failed'));

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Error while creating service 'broken'");

        $container->get('broken');
    }
}
