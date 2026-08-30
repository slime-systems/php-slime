<?php

namespace SlimeSystems\Hatsume;

use Closure;

trait ProcessHelper {
  protected static array $eventHandlers = [];
  protected array $state = [];
  protected array $pendingEvents = [];

  protected static function on(string $eventClass, Closure $handler): void {
    static::$eventHandlers[static::class][$eventClass] = $handler;
  }

  /**
   * Get or access state by reference so $this->state()['key'] = val works.
   */
  public function &state(): array {
    return $this->state;
  }

  public function pendingEvents(): array {
    return $this->pendingEvents;
  }

  protected function apply(object|array ...$events): void {
    $handlers = static::getEventHandlers();

    $flattenedEvents = [];
    foreach ($events as $event) {
      if (is_array($event)) {
        array_push($flattenedEvents, ...$event);
      } else {
        $flattenedEvents[] = $event;
      }
    }

    foreach ($flattenedEvents as $event) {
      $eventClass = get_class($event);

      if (isset($handlers[$eventClass])) {
        // Dynamically binds $this context inside the closure
        $handlers[$eventClass]->call($this, $event);
      }

      $this->pendingEvents[] = $event;
    }
  }

  public static function getEventHandlers(): array {
    return static::$eventHandlers[static::class] ?? [];
  }

  protected function mergeState(array $default, ?array $recorded): array {
    $recordedState = !empty($recorded) ? $recorded : [];
    return array_merge($default, $recordedState);
  }
}
