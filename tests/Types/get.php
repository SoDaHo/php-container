<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Types;

use function PHPStan\Testing\assertType;

use Psr\Container\ContainerInterface;
use Sodaho\Container\Container;
use Sodaho\Container\Tests\Unit\Fixtures\ServiceInterface;
use Sodaho\Container\Tests\Unit\Fixtures\TestService;

/**
 * Analysed by PHPStan (composer analyse), never executed: pins what get() tells static analysis.
 */
function getIsTypedByClassString(Container $container, ContainerInterface $psr, string $id): void
{
    assertType(TestService::class, $container->get(TestService::class));
    assertType(ServiceInterface::class, $container->get(ServiceInterface::class));
    assertType('mixed', $container->get('app.name'));
    assertType('mixed', $container->get($id));
    assertType('mixed', $psr->get(TestService::class));
}
