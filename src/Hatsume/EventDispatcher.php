<?php

namespace SlimeSystems\Hatsume;

readonly class EventDispatcher {
  /** @var list<callable(mixed, array<string, mixed>=): void> */
  private array $handlers;

  /**
   * @param callable(mixed, array<string, mixed>=): void ...$handlers
   */
  public function __construct(callable ...$handlers) {
    $this->handlers = array_values($handlers);
  }

  /**
   * Dispatches events and context to all registered handlers.
   *
   * @param mixed $events
   * @param array<string, mixed> $context
   */
  public function __invoke(mixed $events, array $context = []): void {
    foreach ($this->handlers as $handler) {
      $handler($events, $context);
    }
  }
}
