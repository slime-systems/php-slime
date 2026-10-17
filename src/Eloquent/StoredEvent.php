<?php

namespace SlimeSystems\Eloquent;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use SlimeSystems\Eloquent\Serialization\MessagePackSerializer;
use SlimeSystems\Hatsume\TransitoryEvent;

/**
 * @property int $type_id
 * @property string|int $state_id
 * @property int $position
 * @property string $event_class
 * @property array<string, mixed> $data
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $occurred_at
 */
class StoredEvent extends Model {
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 'stored_events';

  /**
   * The composite primary key associated with the table.
   *
   * @var list<string>
   */
  protected $primaryKey = ['type_id', 'state_id', 'position'];

  /**
   * Indicates if the model's ID is auto-incrementing.
   *
   * @var bool
   */
  public $incrementing = false;

  /**
   * Indicates if the model should be timestamped.
   *
   * @var bool
   */
  public $timestamps = false;

  /**
   * The attributes that are mass assignable.
   *
   * @var list<string>
   */
  protected $fillable = [
    'type_id',
    'state_id',
    'position',
    'event_class',
    'data',
    'metadata',
    'occurred_at',
  ];

  /**
   * The attributes that should be cast.
   *
   * @var array<string, class-string|string>
   */
  protected $casts = [
    'type_id' => 'integer',
    'position' => 'integer',
    'data' => \SlimeSystems\Eloquent\Casts\MessagePackCast::class,
    'metadata' => \SlimeSystems\Eloquent\Casts\MessagePackCast::class,
    'occurred_at' => 'datetime',
  ];

  /**
   * Define default schema blueprint for the stored_events table.
   */
  public static function createBlueprint(Blueprint $table): void {
    $table->unsignedInteger('type_id');
    $table->string('state_id');
    $table->unsignedBigInteger('position');
    $table->string('event_class');
    $table->binary('data');
    $table->binary('metadata')->nullable();
    $table->timestamp('occurred_at', 6)->nullable();

    $table->primary(['type_id', 'state_id', 'position']);
    $table->index(['occurred_at', 'event_class']);
  }

  /**
   * Set the keys for a save update query (enabling composite primary key support).
   *
   * @param Builder<static> $query
   * @return Builder<static>
   */
  protected function setKeysForSaveQuery($query) {
    return $query
      ->where('type_id', $this->getAttribute('type_id'))
      ->where('state_id', $this->getAttribute('state_id'))
      ->where('position', $this->getAttribute('position'));
  }

  /**
   * Get the primary key value for a save query.
   *
   * @return array{type_id: int|null, state_id: string|int|null, position: int|null}
   */
  public function getKey() {
    return [
      'type_id' => $this->getAttribute('type_id'),
      'state_id' => $this->getAttribute('state_id'),
      'position' => $this->getAttribute('position'),
    ];
  }

  /**
   * Relationship to the StateType model.
   *
   * @return BelongsTo<StateType, $this>
   */
  public function type(): BelongsTo {
    return $this->belongsTo(StateType::class, 'type_id');
  }

  /**
   * Reconstitutes the stored event into its original domain event instance.
   */
  public function toEvent(): object {
    /** @var class-string $class */
    $class = (string) $this->getAttribute('event_class');
    if (!class_exists($class)) {
      throw new RuntimeException(sprintf('Event class "%s" does not exist for replay.', $class));
    }

    $data = $this->getAttribute('data') ?? [];
    $metadata = $this->getAttribute('metadata');

    return new $class(
      data: $data,
      metadata: $metadata,
    );
  }

  /**
   * Persists domain events into the stored_events table.
   *
   * Rejects any event implementing TransitoryEvent.
   * Requires 'state_key' and 'next_position' in the metadata array.
   *
   * @param mixed $events Single event, array of events, or collection
   * @param array<string, mixed> $metadata Dispatch metadata from Interactor
   * @return int Number of events persisted
   */
  public static function persistEvents(mixed $events, array $metadata = []): int {
    $eventList = is_iterable($events) ? (is_array($events) ? $events : iterator_to_array($events)) : [$events];

    // Filter out transitory events
    $persistableEvents = [];
    foreach ($eventList as $event) {
      if (!$event instanceof TransitoryEvent) {
        $persistableEvents[] = $event;
      }
    }

    if (empty($persistableEvents)) {
      return 0;
    }

    $stateKey = $metadata['state_key'] ?? null;
    $nextPosition = $metadata['next_position'] ?? null;

    if ($stateKey === null || $nextPosition === null) {
      throw new InvalidArgumentException(
        'Event persistence requires "state_key" and "next_position" in metadata.'
      );
    }

    $resolvedKey = self::resolveStateKey($stateKey);
    $typeId = $resolvedKey['type_id'];
    $stateId = $resolvedKey['state_id'];
    $startPosition = (int) $nextPosition;

    $records = [];
    foreach ($persistableEvents as $offset => $event) {
      $eventData = method_exists($event, 'getData')
        ? $event->getData()
        : ($event->data ?? []);

      $eventMetadata = method_exists($event, 'getMetadata')
        ? $event->getMetadata()
        : ($event->metadata ?? null);

      $occurredAt = self::resolveOccurredAt($eventMetadata, $metadata);

      $records[] = [
        'type_id' => $typeId,
        'state_id' => $stateId,
        'position' => $startPosition + $offset,
        'event_class' => $event::class,
        'data' => MessagePackSerializer::pack($eventData),
        'metadata' => $eventMetadata !== null ? MessagePackSerializer::pack($eventMetadata) : null,
        'occurred_at' => $occurredAt,
      ];
    }

    static::insert($records);

    return count($records);
  }

  /**
   * Resolves raw key input into composite type_id and state_id.
   *
   * @param mixed $key
   * @return array{type_id: int, state_id: string}
   */
  public static function resolveStateKey(mixed $key): array {
    if (is_array($key)) {
      $typeId = $key['type_id'] ?? null;
      if ($typeId === null && isset($key['type'])) {
        $typeId = StateType::idFor((string) $key['type']);
      }

      $stateId = $key['state_id'] ?? $key['id'] ?? null;

      if ($typeId !== null && $stateId !== null) {
        return [
          'type_id' => (int) $typeId,
          'state_id' => (string) $stateId,
        ];
      }
    }

    if (is_string($key) && str_contains($key, ':')) {
      [$typeName, $id] = explode(':', $key, 2);
      return [
        'type_id' => StateType::idFor($typeName),
        'state_id' => (string) $id,
      ];
    }

    return [
      'type_id' => StateType::idFor('default'),
      'state_id' => (string) $key,
    ];
  }

  /**
   * Scope query to all events for a given aggregate state key.
   *
   * @param Builder<static> $query
   * @param mixed $stateKey
   * @return Builder<static>
   */
  public function scopeForAggregate(Builder $query, mixed $stateKey): Builder {
    $resolved = self::resolveStateKey($stateKey);
    return $query
      ->where('type_id', $resolved['type_id'])
      ->where('state_id', $resolved['state_id'])
      ->orderBy('position', 'asc');
  }

  /**
   * Scope query to events starting at or after a position.
   *
   * @param Builder<static> $query
   * @param int $position
   * @return Builder<static>
   */
  public function scopeFromPosition(Builder $query, int $position): Builder {
    return $query->where('position', '>=', $position);
  }

  /**
   * Resolves timestamp from event metadata, dispatch metadata, or current time.
   */
  private static function resolveOccurredAt(mixed $eventMetadata, array $dispatchMetadata): string {
    $time = null;

    if (is_array($eventMetadata) && isset($eventMetadata['occurred_at'])) {
      $time = $eventMetadata['occurred_at'];
    } elseif (isset($dispatchMetadata['occurred_at'])) {
      $time = $dispatchMetadata['occurred_at'];
    }

    if ($time instanceof DateTimeInterface) {
      return $time->format('Y-m-d H:i:s.u');
    }

    if (is_string($time)) {
      return Carbon::parse($time)->format('Y-m-d H:i:s.u');
    }

    return Carbon::now()->format('Y-m-d H:i:s.u');
  }
}
