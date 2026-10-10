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

    public function testImplementationIsStoredUnderItsDeclaredName(): void
    {
        $container = new Container();
        // The class written in another case on purpose
        $container->bind(Fixtures\ServiceInterface::class, 'sodaho\container\tests\unit\fixtures\concreteservice');

        // One entry for the class: the one get() of the class creates
        $this->assertSame($container->get(Fixtures\ConcreteService::class), $container->get(Fixtures\ServiceInterface::class));
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
