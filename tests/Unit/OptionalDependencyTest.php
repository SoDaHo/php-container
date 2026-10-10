<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * Parameters with a default: the default only when the container cannot provide the type.
 */
class OptionalDependencyTest extends TestCase
{
    public function testOptionalDependencyUsesDefault(): void
    {
        $container = new Container();
        $service = $container->get(Fixtures\ServiceWithOptionalDep::class);

        $this->assertNull($service->optional);
    }

    public function testNullableDependencyWithoutDefaultThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve dependency '" . Fixtures\NonExistentInterface::class . "' for parameter 'dep'");

        $container->get(Fixtures\ServiceWithNullableNoDefault::class);
    }

    public function testOptionalEnumDependencyUsesDefault(): void
    {
        $container = new Container();

        $this->assertSame(Fixtures\Mode::Safe, $container->get(Fixtures\ServiceWithEnumDefault::class)->mode);
    }

    public function testOptionalAbstractDependencyUsesDefault(): void
    {
        $container = new Container();

        $this->assertNull($container->get(Fixtures\ServiceWithOptionalAbstract::class)->service);
    }

    public function testOptionalDependencyUsesBindingWhenThereIsOne(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

        $this->assertInstanceOf(
            Fixtures\ConcreteService::class,
            $container->get(Fixtures\ServiceWithOptionalInterface::class)->service
        );
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function brokenBindings(): array
    {
        return [
            'class that does not exist' => [
                [Fixtures\ServiceInterface::class => 'Missing\\Typo'],
                "Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter 'service'",
            ],
            // The abstract class is named by the NotFoundException in getPrevious()
            'abstract class' => [
                [Fixtures\ServiceInterface::class => Fixtures\AbstractService::class],
                "Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter 'service'",
            ],
            // Through a name that is no class: classes that exist cannot form a cycle, each must extend the one before
            'cycle' => [
                [Fixtures\ServiceInterface::class => 'Missing\Cycle', 'Missing\Cycle' => Fixtures\ServiceInterface::class],
                'Circular dependency detected',
            ],
            'interface bound to itself' => [
                [Fixtures\ServiceInterface::class => Fixtures\ServiceInterface::class],
                "Cannot resolve dependency '" . Fixtures\ServiceInterface::class . "' for parameter 'service'",
            ],
        ];
    }

    /**
     * @param array<string, class-string> $bindings
     */
    #[DataProvider('brokenBindings')]
    public function testOptionalDependencyWithABrokenBindingIsAnError(array $bindings, string $message): void
    {
        $container = new Container();
        foreach ($bindings as $interface => $implementation) {
            $container->bind($interface, $implementation);
        }

        // A binding says what is wanted: falling back to the default would hide the mistake
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage($message);

        $container->get(Fixtures\ServiceWithOptionalInterface::class);
    }

    public function testOptionalDependencyThatExistsButCannotBeBuiltStaysAnError(): void
    {
        $container = new Container();

        // ServiceWithConfig is instantiable but needs a string: a wiring mistake, not a missing service
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Cannot resolve primitive parameter 'apiKey'");

        $container->get(Fixtures\ServiceWithOptionalBroken::class);
    }

    public function testObjectDefaultIsUsedWhenNothingIsBound(): void
    {
        $container = new Container();

        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\ServiceWithObjectDefault::class)->logger);
    }

    public function testDefaultOfAnUntypedParameterMayBeAnObjectOrAnEnumCase(): void
    {
        $container = new Container();

        $this->assertInstanceOf(Fixtures\FileLogger::class, $container->get(Fixtures\ServiceWithUntypedObjectDefault::class)->logger);
        $this->assertSame(Fixtures\Mode::Safe, $container->get(Fixtures\ServiceWithUntypedEnumDefault::class)->mode);
    }

    public function testObjectDefaultIsOnlyCreatedWhenItIsUsed(): void
    {
        Fixtures\CountingLogger::$created = 0;

        $bound = new Container()->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $this->assertInstanceOf(Fixtures\FileLogger::class, $bound->get(Fixtures\ServiceWithCountingDefault::class)->logger);
        $this->assertSame(0, Fixtures\CountingLogger::$created);

        $unbound = new Container();
        $this->assertInstanceOf(Fixtures\CountingLogger::class, $unbound->get(Fixtures\ServiceWithCountingDefault::class)->logger);
        $this->assertSame(1, Fixtures\CountingLogger::$created);
    }

    public function testDefaultIsEvaluatedAfterTheDependenciesInFrontOfIt(): void
    {
        Fixtures\BootedService::$booted = false;

        $service = new Container()->get(Fixtures\ServiceWithDefaultAfterDependency::class);

        $this->assertInstanceOf(Fixtures\NeedsBootedService::class, $service->value);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function classesWithThrowingDefault(): array
    {
        return [
            'optional dependency' => [Fixtures\ServiceWithThrowingDefault::class],
            'untyped parameter' => [Fixtures\ServiceWithThrowingUntypedDefault::class],
        ];
    }

    /**
     * @param class-string $id
     */
    #[DataProvider('classesWithThrowingDefault')]
    public function testDefaultThatThrowsIsReportedLikeAFailingConstructor(string $id): void
    {
        $container = new Container();
        $errors = [];
        $container->on('error', function (array $data) use (&$errors) {
            $errors[] = $data;
        });

        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Failed to instantiate '$id'.", $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
            $this->assertStringEndsWith(': Default failed intentionally', (string) $e->getDebugMessage());
        }

        $this->assertCount(1, $errors);
        $this->assertSame($id, $errors[0]['id']);
        $this->assertInstanceOf(\RuntimeException::class, $errors[0]['exception']);
    }

    public function testSubclassThatProvidesEntriesItselfIsAskedForOptionalDependencies(): void
    {
        $service = new Fixtures\ConcreteService();
        $container = new Fixtures\ContainerWithFallback($service);

        $this->assertSame($service, $container->get(Fixtures\ServiceWithOptionalInterface::class)->service);
    }
}
