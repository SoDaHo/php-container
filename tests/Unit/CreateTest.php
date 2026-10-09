<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * The constructor and create(): there are no options, a subclass gets an instance of itself.
 */
class CreateTest extends TestCase
{
    public function testCreateFactoryMethod(): void
    {
        $container = Container::create();

        $this->assertInstanceOf(Container::class, $container);
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function leftoverConfigs(): array
    {
        return [
            'debug' => [['debug' => false]],
            'cache' => [['cacheFile' => '/tmp/container.cache', 'cacheSignature' => 'key']],
            'null value' => [['cacheFile' => null]],
            'unknown key' => [['anything' => 1]],
            'list' => [[true]],
        ];
    }

    /**
     * @param array<mixed> $config
     */
    #[DataProvider('leftoverConfigs')]
    public function testConstructorRejectsConfigLeftOverFromVersionOne(array $config): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('The container has no options: the cache and the debug option were removed in 2.0.');

        new Container($config);
    }

    public function testCreateRejectsConfigLeftOverFromVersionOne(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('The container has no options');

        Container::create(['debug' => true]);
    }

    public function testEmptyConfigIsAccepted(): void
    {
        $this->assertInstanceOf(Container::class, new Container([]));
        $this->assertInstanceOf(Container::class, Container::create([]));
    }

    public function testCreateOnASubclassReturnsTheSubclass(): void
    {
        $container = Fixtures\ContainerWithBootEvent::create();

        $this->assertInstanceOf(Fixtures\ContainerWithBootEvent::class, $container);
        $this->assertSame($container, $container->on('boot', fn () => null));
    }
}
