<?php

declare(strict_types=1);

namespace Sodaho\Container\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Base exception for all container errors.
 *
 * Provides a message and, where there is one, a debug message with a hint for logging.
 * getMessage() can contain the message of a wrapped exception: log it, don't show it to end users.
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
    /**
     * Create a new ContainerException.
     *
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param string|null $debugMessage Debug message with additional details (for logging)
     */
    public function __construct(
        string $message = 'Container error',
        int $code = 0,
        ?\Throwable $previous = null,
        protected ?string $debugMessage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the debug message with additional details.
     *
     * Use this for logging - never expose to end users in production. Null if there is nothing to add.
     */
    public function getDebugMessage(): ?string
    {
        return $this->debugMessage;
    }
}
