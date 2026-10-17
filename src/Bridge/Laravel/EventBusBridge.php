<?php

namespace SlimeSystems\Bridge\Laravel;

use Illuminate\Contracts\Events\Dispatcher;

class EventBusBridge {
  public function __construct(
    private readonly Dispatcher $dispatcher,
  ) {
  }

  /**
   * Dispatches domain events to Laravel's native event dispatcher.
   *
   * @param mixed $events Single event, array of events, or iterable
   * @param array<string, mixed> $metadata Dispatch metadata
   */
  public function __invoke(mixed $events, array $metadata = []): void {
    $eventList = is_iterable($events)
      ? (is_array($events) ? $events : iterator_to_array($events))
      : [$events];

    foreach ($eventList as $event) {
      if (is_object($event)) {
        $this->dispatcher->dispatch($event);
      }
    }
  }

  /**
   * Alias for __invoke to allow explicit call invocation.
   *
   * @param mixed $events
   * @param array<string, mixed> $metadata
   */
  public function call(mixed $events, array $metadata = []): void {
    $this($events, $metadata);
  }
}
