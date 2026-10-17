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
   * Dispatches events and metadata to all registered handlers.
   *
   * @param mixed $events
   * @param array<string, mixed> $metadata
   */
  public function __invoke(mixed $events, array $metadata = []): void {
    foreach ($this->handlers as $handler) {
      $handler($events, $metadata);
    }
  }

  /**
   * Alias for __invoke to allow explicit method calling.
   *
   * @param mixed $events
   * @param array<string, mixed> $metadata
   */
  public function call(mixed $events, array $metadata = []): void {
    $this($events, $metadata);
  }
}
