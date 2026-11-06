<?php

namespace SlimeSystems\Eloquent;

use SlimeSystems\Hatsume\EventDispatcher;

class ApplicationEventHandler {
  private readonly EventDispatcher $dispatcher;

  /**
   * @param callable(mixed, array<string, mixed>=): void $projector Read model projector (usually EventQueue)
   * @param (callable(mixed, array<string, mixed>=): mixed)|null $eventStore Event store persistence handler (defaults to StoredEvent::persistEvents)
   * @param (callable(mixed, array<string, mixed>=): void)|null $eventBus Optional external event bus bridge (e.g. EventBusBridge)
   */
  public function __construct(
    callable  $projector,
    ?callable $eventStore = null,
    ?callable $eventBus = null,
  ) {
    $handlers = [$projector];

    // Default to StoredEvent::persistEvents if not explicitly disabled or overridden
    if ($eventStore !== null) {
      $handlers[] = $eventStore;
    } else {
      $handlers[] = StoredEvent::persistEvents(...);
    }

    if ($eventBus !== null) {
      $handlers[] = $eventBus;
    }

    $this->dispatcher = new EventDispatcher(...$handlers);
  }

  /**
   * Static factory to construct an ApplicationEventHandler.
   *
   * @param callable(mixed, array<string, mixed>=): void $projector
   * @param (callable(mixed, array<string, mixed>=): mixed)|null $eventStore
   * @param (callable(mixed, array<string, mixed>=): void)|null $eventBus
   */
  public static function make(
    callable  $projector,
    ?callable $eventStore = null,
    ?callable $eventBus = null,
  ): self {
    return new self($projector, $eventStore, $eventBus);
  }

  /**
   * Dispatches events and context across projector, event store, and event bus.
   *
   * @param mixed $events
   * @param array<string, mixed> $context
   */
  public function __invoke(mixed $events, array $context = []): void {
    ($this->dispatcher)($events, $context);
  }
}
