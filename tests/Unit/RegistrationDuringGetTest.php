<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;
use Sodaho\Container\Exception\NotFoundException;

/**
 * Nothing can be registered while get() runs: a factory or hook that registers would change what some of the
 * entries on the way get, and not others. Once get() has returned or failed, registering works again.
 */
class RegistrationDuringGetTest extends TestCase
{
    private static function message(string $id): string
    {
        return "Cannot define '$id' while get() is running: register definitions before it, not from a factory or a hook.";
    }

    public function testFactoryCannotDefineItsOwnEntry(): void
    {
        $container = new Container();
        $container->set('service', function (Container $c) {
            $c->set('service', fn () => new Fixtures\AlternativeService());

            return new Fixtures\ConcreteService();
        });

        try {
            $container->get('service');
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Error while creating service 'service'.", $e->getMessage());
            $this->assertSame(self::message('service'), $e->getPrevious()?->getMessage());
        }
    }

    public function testFactoryCannotBindATypeAnEarlierParameterGotTheDefaultFor(): void
    {
        // The service gets null for ServiceInterface first; the factory of its next dependency binds ServiceInterface
        $container = new Container();
        $container->set(Fixtures\TestService::class, function (Container $c) {
            $c->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);

            return new Fixtures\TestService();
        });

        try {
            $container->get(Fixtures\ServiceWithOptionalInterfaceThenService::class);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Error while creating service '" . Fixtures\TestService::class . "'.", $e->getMessage());
            $this->assertSame(self::message(Fixtures\ServiceInterface::class), $e->getPrevious()?->getMessage());
        }
        $this->assertFalse($container->has(Fixtures\ServiceInterface::class));

        // After the failed get(), the binding is registered the way it belongs: before
        $container->bind(Fixtures\ServiceInterface::class, Fixtures\ConcreteService::class);
        $container->set(Fixtures\TestService::class, fn () => new Fixtures\TestService());

        $service = $container->get(Fixtures\ServiceWithOptionalInterfaceThenService::class);
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $service->service);
        $this->assertSame($service->service, $container->get(Fixtures\ServiceInterface::class));
    }

    /**
     * @return array<string, array{callable(Container): mixed, class-string}>
     */
    public static function registrationsFromAResolveHook(): array
    {
        $bind = fn (Container $c) => $c->bind(Fixtures\FirstInterface::class, Fixtures\ConcreteService::class);
        $set = fn (Container $c) => $c->set(Fixtures\FirstInterface::class, fn () => new \stdClass());

        return [
            'bind() when the target is announced' => [$bind, Fixtures\NeedsLogger::class],
            'set() when the target is announced' => [$set, Fixtures\NeedsLogger::class],
            'bind() when a dependency of the target is announced' => [$bind, Fixtures\FileLogger::class],
            'set() when a dependency of the target is announced' => [$set, Fixtures\FileLogger::class],
        ];
    }

    /**
     * @param callable(Container): mixed $register
     * @param class-string $announced
     */
    #[DataProvider('registrationsFromAResolveHook')]
    public function testResolveHookCannotRegister(callable $register, string $announced): void
    {
        // FirstInterface -> NeedsLogger, which itself gets its logger through another binding
        $container = new Container();
        $container->bind(Fixtures\FirstInterface::class, Fixtures\NeedsLogger::class);
        $container->bind(Fixtures\LoggerInterface::class, Fixtures\FileLogger::class);
        $announcements = 0;
        $container->on('resolve', function (array $data) use ($container, $register, $announced, &$announcements) {
            if ($data['id'] === $announced) {
                $announcements++;
                $register($container);
            }
        });

        // The hook's exception undoes the announced entry, so the next get() creates it anew and fails the same way
        foreach ([1, 2] as $attempt) {
            try {
                $container->get(Fixtures\FirstInterface::class);
                $this->fail('Expected ContainerException');
            } catch (ContainerException $e) {
                $this->assertSame(self::message(Fixtures\FirstInterface::class), $e->getMessage());
            }
            $this->assertSame($attempt, $announcements);
        }

        // What has() says stays true: the binding is in place, only the hook keeps failing
        $this->assertTrue($container->has(Fixtures\FirstInterface::class));
    }

    public function testErrorHookCannotRegisterAReplacement(): void
    {
        $container = new Container();
        $container->on('error', function (array $data) use ($container) {
            $id = $data['id'];
            $this->assertIsString($id);
            $container->set($id, fn () => new \stdClass());
        });

        try {
            $container->get('Missing\Service');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException $e) {
            // The hook's exception does not replace the failure: it is in the debug message
            $this->assertSame("Class or service 'Missing\Service' not found.", $e->getMessage());
            $this->assertStringEndsWith(': ' . self::message('Missing\Service'), (string) $e->getDebugMessage());
        }

        // Once get() has failed, the replacement is accepted
        $container->set('Missing\Service', fn () => new \stdClass());

        $this->assertInstanceOf(\stdClass::class, $container->get('Missing\Service'));
    }
}
