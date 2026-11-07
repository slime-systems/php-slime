<?php

namespace SlimeSystems\Http\Concerns;

use Illuminate\Http\Request;

trait InitializesEvents {
  /**
   * Factory helper to instantiate events with ambient request metadata.
   * Override this method in application controllers to provide audit/tracing metadata.
   *
   * @param class-string $eventClass
   * @param array<string, mixed> $data
   * @return object
   */
  protected function initializeEvent(string $eventClass, array $data = []): object {
    return $eventClass::strict(
      data: $data,
      // to be overriden: `metadata: $this->eventMetadata()`,
    );
  }
}

