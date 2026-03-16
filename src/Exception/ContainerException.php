<?php

declare(strict_types=1);

namespace Sodaho\Container\Exception;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Base exception for all container errors.
 *
 * Provides both user-facing message and debug message for logging.
 * In production, show only getMessage(). Use getDebugMessage() for logs.
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
    /**
     * Create a new ContainerException.
     *
     * @param string $message User-facing error message (safe for production)
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
     * Use this for logging - never expose to end users in production.
     */
    public function getDebugMessage(): ?string
    {
        return $this->debugMessage;
    }
}
