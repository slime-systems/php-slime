<?php

namespace SlimeSystems\Hatsume;

readonly class EventQueue extends EventDispatcher {
  /**
   * Iterates over each event and dispatches them individually.
   *
   * @param mixed $events
   * @param array<string, mixed> $metadata
   */
  public function __invoke(mixed $events, array $metadata = []): void {
    $iterable = is_iterable($events) ? $events : [$events];
    foreach ($iterable as $event) {
      parent::__invoke($event, $metadata);
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
