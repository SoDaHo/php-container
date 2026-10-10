<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sodaho\Container\Container;
use Sodaho\Container\Exception\NotFoundException;

/**
 * A copy of the container made while get() runs does not take part in that get().
 */
class CloneTest extends TestCase
{
    public function testCopyMadeInAFactoryCanRegisterAndCreateWhatTheOriginalIsCreating(): void
    {
        $container = new Container();
        $copies = [];
        $container->set('service', function (Container $c) use (&$copies) {
            $copies[] = clone $c;

            return new Fixtures\ConcreteService();
        });
        $service = $container->get('service');
        $copy = $copies[0];

        $copy->set('other', fn () => 'value');
        $this->assertSame('value', $copy->get('other'));

        // Not a circular dependency: the copy creates the entry on its own
        $this->assertInstanceOf(Fixtures\ConcreteService::class, $copy->get('service'));
        $this->assertNotSame($service, $copy->get('service'));
    }

    public function testCopyMadeInAnErrorHookReportsItsOwnFailures(): void
    {
        $container = new Container();
        $copies = [];
        $container->on('error', function () use ($container, &$copies) {
            $copies[] = clone $container;
        });

        try {
            $container->get('Missing\Service');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException) {
            // Expected
        }
        $copy = $copies[0];
        $reported = 0;
        $copy->on('error', function () use (&$reported) {
            $reported++;
        });

        try {
            $copy->get('Missing\Other');
            $this->fail('Expected NotFoundException');
        } catch (NotFoundException) {
            // Expected
        }

        // The hook copied from the original ran as well and made another copy
        $this->assertSame(1, $reported);
        $this->assertCount(2, $copies);
    }
}
