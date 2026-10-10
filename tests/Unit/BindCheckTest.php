<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * What get() of a class returns is an instance of it: bind() checks the classes it is given, and get() checks
 * what a factory returns under a class name and what a binding ends at.
 */
class BindCheckTest extends TestCase
{
    public function testImplementationThatIsNoSubtypeOfTheInterfaceIsRejected(): void
    {
        $container = new Container();

        try {
            $container->bind(Fixtures\ServiceInterface::class, Fixtures\FileLogger::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Cannot bind '" . Fixtures\ServiceInterface::class . "' to '" . Fixtures\FileLogger::class
                . "': it neither implements nor extends '" . Fixtures\ServiceInterface::class . "'.",
                $e->getMessage()
            );
        }

        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));
    }

    public function testClassesThatExistCannotFormACycle(): void
    {
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\SecondInterface::class);

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("': it neither implements nor extends '" . Fixtures\SecondInterface::class . "'.");

        $container->bind(Fixtures\SecondInterface::class, Fixtures\FirstInterface::class);
    }

    public function testImplementationWrittenOtherwiseThanDeclaredIsRejected(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            "Cannot bind '" . Fixtures\ServiceInterface::class . '\' to \'sodaho\container\tests\unit\fixtures\concreteservice\': name it as declared, \''
            . Fixtures\ConcreteService::class . "'."
        );

        // The class written in another case on purpose
        $container->bind(Fixtures\ServiceInterface::class, 'sodaho\container\tests\unit\fixtures\concreteservice');
    }

    public function testImplementationNamedByAClassAliasIsRejectedRatherThanPassingOverItsFactory(): void
    {
        // A factory under the alias: renaming the binding to the declared class would autowire past it
        $alias = 'Sodaho\Container\Tests\Unit\Fixtures\LegacyConcreteService';
        if (!class_exists($alias, false)) {
            class_alias(Fixtures\ConcreteService::class, $alias);
        }
        $container = new Container();
        $container->set($alias, fn () => new Fixtures\ConcreteService());

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage(
            "Cannot bind '" . Fixtures\ServiceInterface::class . "' to '$alias': name it as declared, '" . Fixtures\ConcreteService::class . "'."
        );

        // @phpstan-ignore argument.type (the name of a class_alias(), which PHPStan does not know)
        $container->bind(Fixtures\ServiceInterface::class, $alias);
    }

    public function testEntryCreatedBeforeItsClassExistedIsCheckedWhenItIsFound(): void
    {
        $class = Fixtures\LateLoaded\LateDeclared::class;
        $container = new Container();
        $container->set($class, fn () => null);
        // No class of that name yet: the factory's null is the entry of a name (asked for as a plain string id)
        $get = fn (string $id): mixed => $container->get($id);
        $this->assertNull($get($class));

        $load = static function (string $name) use ($class): void {
            if ($name === $class) {
                require __DIR__ . '/Fixtures/LateLoaded/late_declared.php';
            }
        };
        spl_autoload_register($load);
        try {
            $this->assertTrue(class_exists($class));

            $this->expectException(ContainerException::class);
            $this->expectExceptionMessage("Cannot resolve '$class': its entry is null, not an instance of it.");
            $container->get($class);
        } finally {
            spl_autoload_unregister($load);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function interfacesWrittenOtherwise(): array
    {
        return [
            'another case' => [strtolower(Fixtures\ServiceInterface::class)],
            'leading backslash' => ['\\' . Fixtures\ServiceInterface::class],
        ];
    }

    #[DataProvider('interfacesWrittenOtherwise')]
    public function testInterfaceWrittenOtherwiseThanDeclaredIsRejected(string $interface): void
    {
        // Autowiring would find the binding under the declared name, get() with this spelling would not
        $container = new Container();

        try {
            $container->bind($interface, Fixtures\ConcreteService::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Cannot bind '$interface': name it as declared, '" . Fixtures\ServiceInterface::class . "'.", $e->getMessage());
        }

        $this->assertFalse($container->has($interface));
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function factoryResultsOfAnotherType(): array
    {
        return [
            'another class' => [new Fixtures\FileLogger(), Fixtures\FileLogger::class],
            'null' => [null, 'null'],
        ];
    }

    #[DataProvider('factoryResultsOfAnotherType')]
    public function testFactoryUnderAClassNameMustReturnAnInstanceOfIt(mixed $result, string $type): void
    {
        $container = new Container();
        $container->set(Fixtures\ServiceInterface::class, fn () => $result);
        $reported = [];
        $container->on('error', function (array $data) use (&$reported) {
            $reported[] = $data['id'];
        });

        try {
            $container->get(Fixtures\ServiceInterface::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame(
                "Error while creating service '" . Fixtures\ServiceInterface::class . "': the factory returned $type, not an instance of it.",
                $e->getMessage()
            );
        }
        $this->assertSame([Fixtures\ServiceInterface::class], $reported);

        // Nothing was created: the definition can be corrected
        $container->set(Fixtures\ServiceInterface::class, fn () => new Fixtures\ConcreteService());
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get(Fixtures\ServiceInterface::class));
    }

    public function testBindingThatEndsAtAnEntryOfAnotherTypeIsAnError(): void
    {
        // bind() cannot check a name that is no class: get() checks what the binding ends at
        $container = new Container();
        // @phpstan-ignore argument.type (a name that is no class, with a factory)
        $container->bind(Fixtures\LoggerInterface::class, 'logger.file');
        $container->set('logger.file', fn () => new Fixtures\ConcreteService());
        $message = "Cannot resolve '" . Fixtures\LoggerInterface::class . "': it is bound to 'logger.file', whose entry is "
            . Fixtures\ConcreteService::class . ', not an instance of it.';

        foreach ([Fixtures\LoggerInterface::class, Fixtures\NeedsLogger::class] as $id) {
            try {
                $container->get($id);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertNotInstanceOf(NotFoundException::class, $e);
                $this->assertSame($message, $e->getMessage());
            }
        }

        // The entry of the name itself is fine
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $container->get('logger.file'));
    }

    public function testInterfaceBoundToItselfSaysSo(): void
    {
        $container = new Container();
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ServiceInterface::class);

        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));
        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage(
            "Interface '" . Fixtures\ServiceInterface::class . "' is bound to itself: bind it to a class that implements it."
        );

        $container->get(Fixtures\ServiceInterface::class);
    }
}
