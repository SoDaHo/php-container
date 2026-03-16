<?php

declare(strict_types=1);

namespace Sodaho\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Exception thrown when a requested entry is not found in the container.
 */
class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
