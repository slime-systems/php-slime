<?php

namespace SlimeSystems\Hatsume;

use Closure;
use SlimeSystems\Internal\WrapsHandlers;

readonly class EventDispatcher {
  use WrapsHandlers;

  /** @var Closure */
  private array $handlers;

  public function __construct(callable ...$handlers) {
    $this->handlers = array_map($this->wrapHandler(...), $handlers);
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
}
