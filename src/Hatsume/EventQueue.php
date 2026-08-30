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
    foreach ($events as $event) {
      parent::__invoke($event, $metadata);
    }
  }
}
