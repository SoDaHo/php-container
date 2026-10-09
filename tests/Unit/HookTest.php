<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * on(): which events exist, and that every hook for an event runs in order.
 */
class HookTest extends TestCase
{
    public function testOnReturnsContainerForChaining(): void
    {
        $container = new Container();

        $result = $container->on('resolve', fn () => null);

        $this->assertSame($container, $result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownEvents(): array
    {
        return [
            'removed in 2.0' => ['cacheHit'],
            'removed in 2.0, too' => ['cacheMiss'],
            'typo' => ['resolved'],
            'case' => ['Error'],
            'empty' => [''],
        ];
    }

    #[DataProvider('unknownEvents')]
    public function testOnThrowsForAnEventThatDoesNotExist(string $event): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Unknown event '$event'. Available: resolve, error.");

        $container->on($event, fn () => null);
    }

    public function testSubclassAddsItsOwnEvents(): void
    {
        $container = new Fixtures\ContainerWithBootEvent();
        $seen = [];
        $container->on('boot', function (array $data) use (&$seen) {
            $seen[] = $data['container'];
        });
        $container->on('resolve', fn () => null);

        $container->boot();

        $this->assertSame([$container], $seen);
    }

    public function testSubclassEventIsUnknownToTheContainerItself(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage("Unknown event 'boot'. Available: resolve, error.");

        new Container()->on('boot', fn () => null);
    }

    public function testTwoHooksForOneEventBothRunInOrder(): void
    {
        $container = new Container();
        $order = [];
        $container->on('resolve', function () use (&$order) {
            $order[] = 'first';
        });
        $container->on('resolve', function () use (&$order) {
            $order[] = 'second';
        });

        $container->get(\stdClass::class);

        $this->assertSame(['first', 'second'], $order);
    }
}
