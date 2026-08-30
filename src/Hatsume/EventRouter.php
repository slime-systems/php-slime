<?php

namespace SlimeSystems\Hatsume;

use Closure;
use SlimeSystems\Internal\WrapsHandlers;

readonly class EventRouter {
  use WrapsHandlers;

  /** @var Closure */
  private array $handlersMap;

  /**
   * @param array<class-string, callable> $handlersMap
   */
  public function __construct(array $handlersMap = []) {
    $this->handlersMap = array_map($this->wrapHandler(...), $handlersMap);
  }

  /**
   * Routes an event to its registered class handler.
   *
   * @param object $event
   * @param array<string, mixed> $metadata
   */
  public function __invoke(object $event, array $metadata = []): void {
    $this->handlersMap[$event::class]?->__invoke($event, $metadata);
  }
}
