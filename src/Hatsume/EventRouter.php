<?php

namespace SlimeSystems\Hatsume;

readonly class EventRouter {
  /**
   * @param array<class-string, callable(object, array<string, mixed>=): void> $handlersMap
   */
  public function __construct(
    private array $handlersMap = []
  ) {
  }

  /**
   * Routes an event to its registered class handler.
   *
   * @param object $event
   * @param array<string, mixed> $metadata
   */
  public function __invoke(object $event, array $metadata = []): void {
    $handler = $this->handlersMap[$event::class] ?? null;
    if ($handler !== null) {
      $handler($event, $metadata);
    }
  }

  /**
   * Alias for __invoke to allow explicit method calling.
   *
   * @param object $event
   * @param array<string, mixed> $metadata
   */
  public function call(object $event, array $metadata = []): void {
    $this($event, $metadata);
  }
}
