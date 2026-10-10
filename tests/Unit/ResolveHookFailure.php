<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit;

use Sodaho\Container\Container;
use Sodaho\Container\Exception\ContainerException;

/**
 * What get() throws when a resolve hook threw 'Hook failed': a ContainerException naming the entry whose hook failed,
 * with the hook's exception in getPrevious().
 */
trait ResolveHookFailure
{
    private function getFails(Container $container, string $id, ?string $announced = null): void
    {
        try {
            $container->get($id);
            $this->fail('Expected ContainerException');
        } catch (ContainerException $e) {
            $this->assertSame("Resolve hook failed for '" . ($announced ?? $id) . "'.", $e->getMessage());
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\RuntimeException::class, $previous);
            $this->assertSame('Hook failed', $previous->getMessage());
        }
    }
}
