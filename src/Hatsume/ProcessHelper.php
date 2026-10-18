<?php

namespace SlimeSystems\Hatsume;

use Closure;

trait ProcessHelper {
  /** @var array<string, Closure> */
  protected array $eventHandlers = [];
  protected array $state = [];
  protected array $pendingEvents = [];

  /**
   * Get or access state by reference so $this->state()['key'] = val works.
   */
  public function &state(): array {
    return $this->state;
  }

  public function pendingEvents(): array {
    return $this->pendingEvents;
  }

  public function getEventHandlers(): array {
    if (empty($this->eventHandlers)) {
      $this->registerEventHandlers();
    }
    return $this->eventHandlers;
  }

  /**
   * Hook for registering event handlers. Override in process classes.
   */
  protected function registerEventHandlers(): void {
  }

  /**
   * Register an event handler on the process instance.
   */
  protected function on(string $eventClass, Closure $handler): void {
    $this->eventHandlers[$eventClass] = $handler;
  }

  protected function apply(object|array|null ...$events): void {
    if (empty($this->eventHandlers)) {
      $this->registerEventHandlers();
    }

    $flattenedEvents = [];
    foreach ($events as $event) {
      if ($event === null) {
        continue;
      }
      if (is_array($event)) {
        array_push($flattenedEvents, ...$event);
      } else {
        $flattenedEvents[] = $event;
      }
    }

    foreach ($flattenedEvents as $event) {
      if ($event === null) {
        continue;
      }
      $eventClass = get_class($event);

      if (isset($this->eventHandlers[$eventClass])) {
        // Dynamically binds $this context inside the closure
        $this->eventHandlers[$eventClass]->call($this, $event);
      }

      $this->pendingEvents[] = $event;
    }
  }

  protected function mergeState(array $default, ?array $recorded): array {
    $recordedState = !empty($recorded) ? $recorded : [];
    return array_merge($default, $recordedState);
  }
}
