<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;

/**
 * A type names the class however the code spells it: the container looks the class up under its declared name.
 */
class TypeNameTest extends TestCase
{
    public function testTypeInAnotherCaseGetsTheEntryOfTheClass(): void
    {
        $service = new Fixtures\TestService();
        $container = new Container();
        $container->set(Fixtures\TestService::class, fn () => $service);

        // Not a second entry next to the one the factory creates
        $this->assertSame($service, $container->get(Fixtures\ServiceWithLowercaseType::class)->service);
        $this->assertSame($service, $container->get(Fixtures\TestService::class));
    }

    public function testOptionalTypeInAnotherCaseUsesTheBinding(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ServiceWithOptionalLowercaseInterface::class)->service
        );
    }

    public function testOptionalTypeThatDoesNotExistGetsItsDefault(): void
    {
        $container = new Container();

        $this->assertNull($container->get(Fixtures\ServiceWithOptionalMissingClass::class)->thing);
    }
}
