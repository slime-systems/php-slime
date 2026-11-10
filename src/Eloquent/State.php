<?php

declare(strict_types = 1);

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use SlimeSystems\Eloquent\Casts\MessagePackCast;
use SlimeSystems\Eloquent\Concerns\HasStateKey;

/**
 * @property int $type_id
 * @property string $state_id
 * @property array<string, mixed> $state
 * @property int $next_position
 * @property int $lock_version
 * @property-read string|null $type_name
 */
class State extends Model {
  use HasStateKey;

  public $incrementing = false;
  public $timestamps = false;
  protected $table = 'process_states';
  protected $primaryKey = ['type_id', 'state_id'];
  protected $guarded = [];

  protected $attributes = [
    'next_position' => 1,
    'lock_version' => 0,
  ];

  protected $casts = [
    'type_id' => 'integer',
    'state' => MessagePackCast::class,
    'next_position' => 'integer',
    'lock_version' => 'integer',
  ];

  public static function createBlueprint(Blueprint $table, ...$options): void {
    static::createStateKeyBlueprint($table);
    $table->binary('state')->nullable();
    $table->unsignedBigInteger('next_position')->default(1);
    $table->unsignedBigInteger('lock_version')->default(0);

    if ($options['sharding'] ?? false) {
      $table->unsignedInteger($options['shardColumn'] ?? 'query_shard_key')->default(0)->index();
    }

    $table->primary(['type_id', 'state_id']);
  }

  public static function find($id, $columns = ['*']): ?static {
    if (is_array($id)) {
      return static::whereStateKey($id)->first($columns);
    }
    return parent::find($id, $columns);
  }

  public static function findByTypeAndId(int|string $type, string|int $stateId, array $columns = ['*']): ?static {
    return static::find(['type' => $type, 'id' => $stateId], $columns);
  }

  public function scopeForAggregate(Builder $query, mixed $key): Builder {
    return $this->scopeWhereStateKey($query, $key);
  }

  public function getKey(): array {
    return [
      'type_id' => $this->getAttribute('type_id'),
      'state_id' => $this->getAttribute('state_id'),
    ];
  }

  public function storedEvents(): Builder {
    return StoredEvent::forAggregate($this->getKey());
  }

  protected function setKeysForSaveQuery($query) {
    return $query
      ->where('type_id', $this->getOriginal('type_id') ?? $this->getAttribute('type_id'))
      ->where('state_id', $this->getOriginal('state_id') ?? $this->getAttribute('state_id'));
  }
}
