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
   * @param array<string, mixed> $context Dispatch context
   */
  public function __invoke(mixed $events, array $context = []): void {
    $eventList = is_iterable($events)
      ? (is_array($events) ? $events : iterator_to_array($events))
      : [$events];

    foreach ($eventList as $event) {
      if (is_object($event)) {
        $this->dispatcher->dispatch($event);
      }
    }
  }
}
