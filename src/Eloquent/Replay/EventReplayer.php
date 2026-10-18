<?php

namespace SlimeSystems\Eloquent\Replay;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use SlimeSystems\Eloquent\StoredEvent;

class EventReplayer {
  /**
   * @param class-string<StoredEvent>|StoredEvent $model
   */
  public function __construct(
    private readonly string|StoredEvent $model = StoredEvent::class,
  ) {
  }

  /**
   * Static convenience helper to replay an aggregate's events.
   */
  public static function forAggregate(
    mixed    $stateKey,
    callable $handler,
    int      $fromPosition = 1,
    ?int     $toPosition = null,
    int      $chunkSize = 500,
  ): int {
    return (new self())->replayAggregate($stateKey, $handler, $fromPosition, $toPosition, $chunkSize);
  }

  /**
   * Replays events for a specific aggregate state key into a projection handler.
   *
   * @param mixed $stateKey
   * @param callable(object, array<string, mixed>=): void $handler
   * @param int $fromPosition Starting position (inclusive, defaults to 1)
   * @param int|null $toPosition Ending position (inclusive, optional)
   * @param int $chunkSize Chunk size for streaming from database
   * @return int Total number of events replayed
   */
  public function replayAggregate(
    mixed    $stateKey,
    callable $handler,
    int      $fromPosition = 1,
    ?int     $toPosition = null,
    int      $chunkSize = 500,
  ): int {
    $modelClass = is_string($this->model) ? $this->model : $this->model::class;
    $resolvedKey = $modelClass::resolveStateKey($stateKey);

    /** @var Builder<StoredEvent> $query */
    $query = $modelClass::query()
      ->where('type_id', $resolvedKey['type_id'])
      ->where('state_id', $resolvedKey['state_id'])
      ->where('position', '>=', $fromPosition)
      ->orderBy('position', 'asc');

    if ($toPosition !== null) {
      $query->where('position', '<=', $toPosition);
    }

    $count = 0;
    $query->chunk($chunkSize, function (Collection $chunk) use ($handler, $stateKey, &$count) {
      /** @var StoredEvent $storedEvent */
      foreach ($chunk as $storedEvent) {
        $event = $storedEvent->toEvent();
        $metadata = [
          'state_key' => $stateKey,
          'type_id' => $storedEvent->type_id,
          'state_id' => $storedEvent->state_id,
          'position' => $storedEvent->position,
          'occurred_at' => $storedEvent->occurred_at,
        ];

        $handler($event, $metadata);
        $count++;
      }
    });

    return $count;
  }

  /**
   * Static convenience helper to replay all events.
   */
  public static function all(
    callable $handler,
    ?int     $typeId = null,
    int      $chunkSize = 500,
  ): int {
    return (new self())->replayAll($handler, $typeId, $chunkSize);
  }

  /**
   * Replays all stored events in historical chronological order across all aggregates.
   *
   * @param callable(object, array<string, mixed>=): void $handler
   * @param int|null $typeId Optional filter by StateType ID
   * @param int $chunkSize
   * @return int Total number of events replayed
   */
  public function replayAll(
    callable $handler,
    ?int     $typeId = null,
    int      $chunkSize = 500,
  ): int {
    $modelClass = is_string($this->model) ? $this->model : $this->model::class;

    /** @var Builder<StoredEvent> $query */
    $query = $modelClass::query()
      ->orderBy('occurred_at', 'asc')
      ->orderBy('position', 'asc');

    if ($typeId !== null) {
      $query->where('type_id', $typeId);
    }

    $count = 0;
    $query->chunk($chunkSize, function (Collection $chunk) use ($handler, &$count) {
      /** @var StoredEvent $storedEvent */
      foreach ($chunk as $storedEvent) {
        $event = $storedEvent->toEvent();
        $metadata = [
          'state_key' => [
            'type_id' => $storedEvent->type_id,
            'state_id' => $storedEvent->state_id,
          ],
          'type_id' => $storedEvent->type_id,
          'state_id' => $storedEvent->state_id,
          'position' => $storedEvent->position,
          'occurred_at' => $storedEvent->occurred_at,
        ];

        $handler($event, $metadata);
        $count++;
      }
    });

    return $count;
  }
}
