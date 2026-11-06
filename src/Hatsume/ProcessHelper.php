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

  /**
   * Hook for registering event handlers. Override in process classes.
   * Be sure to call this method in the constructor of classes that use this trait.
   */
  protected function registerEventHandlers(): void {
  }

  /**
   * Register an event handler on the process instance.
   */
  protected function on(string $eventClass, Closure $handler): void {
    $this->eventHandlers[$eventClass] = $handler;
  }

  protected function apply(mixed ...$events): array {
    $applied = [];
    $queue = $events;

    while (!empty($queue)) {
      $item = array_shift($queue);
      if ($item === null) {
        continue;
      }
      if (is_array($item)) {
        array_unshift($queue, ...$item);
        continue;
      }
      if (is_object($item)) {
        ($this->eventHandlers[$item::class] ?? null)?->__invoke($item);
        $this->pendingEvents[] = $item;
        $applied[] = $item;
      }
    }

    return $applied;
  }

  protected function mergeState(array $default, ?array $recorded): array {
    return array_merge($default, $recorded ?? []);
  }
}
