<?php

namespace SlimeSystems\Hatsume;

use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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

  private function apply(mixed ...$events): array {
    $handlers = $this->eventHandlers;
    return new Collection($events)
      ->flatMap(Arr::wrap(...))
      ->filter()
      ->each(function (object $event) use ($handlers) {
        $handlers[$event::class]?->__invoke($event);
        $this->pendingEvents[] = $event;
      })
      ->all();
  }

  protected function mergeState(array $default, ?array $recorded): array {
    $recordedState = !empty($recorded) ? $recorded : [];
    return array_merge($default, $recordedState);
  }
}
