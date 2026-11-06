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
   * @param array<string, mixed> $context
   */
  public function __invoke(object $event, array $context = []): void {
    $handler = $this->handlersMap[$event::class] ?? null;
    if ($handler !== null) {
      $handler($event, $context);
    }
  }
}
