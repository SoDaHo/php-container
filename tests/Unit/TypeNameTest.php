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

    public function testOptionalTypeInAnotherCaseUsesTheBindingOfAnInterfaceThatWasNotLoaded(): void
    {
        // An autoloader that finds a class under its declared name only, like PSR-4 on a case-sensitive file system:
        // the type in another case finds the interface because bind() loaded it
        $files = [
            Fixtures\LateLoaded\LateInterface::class => 'late_interface.php',
            Fixtures\LateLoaded\LateImplementation::class => 'late_implementation.php',
            Fixtures\LateLoaded\LateConsumer::class => 'late_consumer.php',
        ];
        $load = static function (string $class) use ($files): void {
            if (isset($files[$class])) {
                require __DIR__ . '/Fixtures/LateLoaded/' . $files[$class];
            }
        };
        spl_autoload_register($load);

        try {
            $this->assertFalse(interface_exists(Fixtures\LateLoaded\LateInterface::class, false));
            $container = new Container();
            $container->bind(Fixtures\LateLoaded\LateInterface::class, Fixtures\LateLoaded\LateImplementation::class);

            $consumer = $container->get(Fixtures\LateLoaded\LateConsumer::class);

            $this->assertInstanceOf(Fixtures\LateLoaded\LateImplementation::class, $consumer->late);
        } finally {
            spl_autoload_unregister($load);
        }
    }

    public function testOptionalTypeThatDoesNotExistGetsItsDefault(): void
    {
        $container = new Container();

        $this->assertNull($container->get(Fixtures\ServiceWithOptionalMissingClass::class)->thing);
    }
}
