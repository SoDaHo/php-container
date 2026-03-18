<?php

declare(strict_types=1);

namespace Sodaho\Container\Tests\Unit\Fixtures;

/**
 * Fixtures requiring PHP 8.2+ (DNF Types).
 *
 * This file MUST be separate because PHP 8.1 cannot parse DNF type syntax.
 */
class ServiceWithIntersectionNullableDefault
{
    public function __construct(public (ServiceInterface&\Countable)|null $value = null)
    {
    }
}
