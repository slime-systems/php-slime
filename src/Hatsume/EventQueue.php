<?php

namespace SlimeSystems\Hatsume;

readonly class EventQueue extends EventDispatcher {
  /**
   * Iterates over each event and dispatches them individually.
   *
   * @param mixed $events
   * @param array<string, mixed> $context
   */
  public function __invoke(mixed $events, array $context = []): void {
    $iterable = is_iterable($events) ? $events : [$events];
    foreach ($iterable as $event) {
      parent::__invoke($event, $context);
    }
  }
}
