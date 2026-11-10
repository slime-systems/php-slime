<?php

declare(strict_types = 1);

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use RuntimeException;
use SlimeSystems\Eloquent\Casts\MessagePackCast;
use SlimeSystems\Eloquent\Concerns\HasStateKey;
use SlimeSystems\Eloquent\Serialization\MessagePackSerializer;
use SlimeSystems\Hatsume\TransitoryEvent;

/**
 * @property int $type_id
 * @property string $state_id
 * @property int $position
 * @property int $event_type_id
 * @property array<string, mixed> $data
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $occurred_at
 * @property-read string|null $type_name
 * @property-read string|null $event_class
 */
class StoredEvent extends Model {
  use HasStateKey;

  public $incrementing = false;
  public $timestamps = false;
  protected $table = 'stored_events';
  protected $primaryKey = ['type_id', 'state_id', 'position'];
  protected $guarded = [];

  protected $casts = [
    'type_id' => 'integer',
    'position' => 'integer',
    'event_type_id' => 'integer',
    'data' => MessagePackCast::class,
    'metadata' => MessagePackCast::class,
    'occurred_at' => 'datetime',
  ];

  public static function createBlueprint(Blueprint $table, ...$options): void {
    static::createStateKeyBlueprint($table);
    $table->unsignedBigInteger('position');
    $table->unsignedInteger('event_type_id');
    $table->binary('data');
    $table->binary('metadata')->nullable();
    $table->timestamp('occurred_at', 6)->nullable();

    $table->primary(['type_id', 'state_id', 'position']);
    $table->index(['occurred_at', 'event_type_id']);
  }

  public static function persistEvents(mixed $events, array $context = []): int {
    $eventList = is_iterable($events) ? (is_array($events) ? $events : iterator_to_array($events)) : [$events];
    $persistableEvents = array_values(array_filter($eventList, fn($e) => !$e instanceof TransitoryEvent));

    if (empty($persistableEvents)) {
      return 0;
    }

    $resolvedKey = static::resolveStateKey($context['_state_key']);
    $typeId = $resolvedKey['type_id'];
    $stateId = $resolvedKey['state_id'];
    $startPosition = (int)$context['_position'];

    $records = [];
    foreach ($persistableEvents as $offset => $event) {
      $records[] = [
        'type_id' => $typeId,
        'state_id' => $stateId,
        'position' => $startPosition + $offset,
        'event_type_id' => EventType::idFor($event::class),
        'data' => MessagePackSerializer::pack($event->data ?? []),
        'metadata' => isset($event->metadata) ? MessagePackSerializer::pack($event->metadata) : null,
        'occurred_at' => $event->occurredAt?->format('Y-m-d H:i:s.u') ?? Carbon::now()->format('Y-m-d H:i:s.u'),
      ];
    }

    static::insert($records);

    return count($records);
  }

  public function toEvent(): object {
    /** @var class-string|null $class */
    $class = $this->event_class;
    if ($class === null || !class_exists($class)) {
      throw new RuntimeException(sprintf('Event class "%s" does not exist for replay.', $class ?? 'null'));
    }

    return new $class(
      data: $this->data ?? [],
      metadata: $this->metadata,
      occurredAt: $this->occurred_at,
    );
  }

  protected function eventClass(): Attribute {
    return Attribute::get(fn() => $this->event_type_id !== null ? EventType::nameFor((int)$this->event_type_id) : null);
  }

  public function getEventClass(): ?string {
    return $this->event_class;
  }

  public function scopeForAggregate(Builder $query, mixed $stateKey): Builder {
    return $this->scopeWhereStateKey($query, $stateKey)->orderBy('position', 'asc');
  }

  public function scopeFromPosition(Builder $query, int $position): Builder {
    return $query->where('position', '>=', $position);
  }
}
