<?php

declare(strict_types=1);

namespace Sodaho\Container\Traits;

/**
 * Trait for event hooks.
 *
 * Provides on() for registering and trigger() for firing events.
 * "Fail Hard" implementation: Exceptions in hooks bubble up to the application.
 */
trait HasHooks
{
    /** @var array<string, array<callable>> */
    private array $hooks = [];

    /**
     * Register a hook callback for an event.
     *
     * @param string $event Event name (e.g., 'resolve', 'error')
     * @param callable $callback Callback receiving event data array
     */
    public function on(string $event, callable $callback): static
    {
        $this->hooks[$event][] = $callback;
        return $this;
    }

    /**
     * Trigger all callbacks for an event.
     *
     * @param string $event Event name
     * @param array<string, mixed> $data Event data passed to callbacks
     */
    protected function trigger(string $event, array $data): void
    {
        foreach ($this->hooks[$event] ?? [] as $callback) {
            $callback($data);
        }
    }
}
