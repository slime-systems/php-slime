<?php

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use SlimeSystems\Eloquent\Casts\MessagePackCast;

/**
 * @property int $type_id
 * @property string|int $state_id
 * @property array<string, mixed> $state
 * @property int $next_position
 * @property int $lock_version
 */
class State extends Model {
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
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 'process_states';
  /**
   * The composite primary key associated with the table.
   *
   * @var list<string>
   */
  protected $primaryKey = ['type_id', 'state_id'];
  /**
   * The attributes that aren't mass assignable.
   *
   * @var list<string>
   */
  protected $guarded = [];

  /**
   * The model's default attribute values.
   *
   * @var array<string, mixed>
   */
  protected $attributes = [
    'next_position' => 1,
    'lock_version' => 0,
  ];

  /**
   * The attributes that should be cast.
   *
   * @var array<string, class-string|string>
   */
  protected $casts = [
    'type_id' => 'integer',
    'state' => MessagePackCast::class,
    'next_position' => 'integer',
    'lock_version' => 'integer',
  ];

  /**
   * Define default schema blueprint for the process_states table.
   */
  public static function createBlueprint(Blueprint $table): void {
    $table->unsignedInteger('type_id');
    $table->string('state_id');
    $table->binary('state')->nullable();
    $table->unsignedBigInteger('next_position')->default(1);
    $table->unsignedBigInteger('lock_version')->default(0);

    $table->primary(['type_id', 'state_id']);
  }

  /**
   * Resolve composite key attributes from a structured array.
   *
   * In Slime domain architecture, `type_id` represents the aggregate/process type
   * discriminator (e.g. 'account', 'project', 'payment:opn:credit-card-transaction').
   * It exhibits strictly LOW cardinality (bounded by the number of domain processes,
   * typically dozens to hundreds) and is interned via StateType for memory & index
   * efficiency. Conversely, `state_id` represents individual instance IDs and exhibits
   * HIGH cardinality.
   *
   * @param array{type?: string, type_id?: int, id?: string|int, state_id?: string|int} $key
   * @return array{type_id: int, state_id: string}
   */
  public static function resolveKey(array $key): array {
    return [
      'type_id' => (int)($key['type_id'] ?? StateType::idFor((string)$key['type'])),
      'state_id' => (string)($key['state_id'] ?? $key['id']),
    ];
  }

  /**
   * Find a model by its composite array primary key.
   *
   * @param array{type?: string, type_id?: int, id?: string|int, state_id?: string|int} $id
   * @param list<string> $columns
   * @return static|null
   */
  public static function find($id, $columns = ['*']) {
    if (is_array($id)) {
      $keys = static::resolveKey($id);
      return static::where('type_id', $keys['type_id'])
        ->where('state_id', $keys['state_id'])
        ->first($columns);
    }
    return parent::find($id, $columns);
  }

  /**
   * Find a state record by string type or integer type_id and state_id.
   */
  public static function findByTypeAndId(int|string $type, string|int $stateId): ?self {
    $typeId = is_int($type) ? $type : StateType::idFor($type);
    return static::where('type_id', $typeId)->where('state_id', (string)$stateId)->first();
  }

  /**
   * Get the primary key value for a save query.
   *
   * @return array{type_id: int|null, state_id: string|int|null}
   */
  public function getKey() {
    return [
      'type_id' => $this->getAttribute('type_id'),
      'state_id' => $this->getAttribute('state_id'),
    ];
  }

  /**
   * Convenience getter for type_id.
   */
  public function getTypeId(): int {
    return (int)$this->getAttribute('type_id');
  }

  /**
   * Convenience getter for string type name resolved from StateType.
   */
  public function getTypeName(): ?string {
    $typeId = $this->getAttribute('type_id');
    return $typeId !== null ? StateType::nameFor((int)$typeId) : null;
  }

  /**
   * Convenience getter for state_id (returns int if numeric integer, string otherwise).
   */
  public function getStateId(): string|int {
    $stateId = $this->getAttribute('state_id');
    if (is_int($stateId)) {
      return $stateId;
    }
    if (is_numeric($stateId) && filter_var($stateId, FILTER_VALIDATE_INT) !== false) {
      return (int)$stateId;
    }
    return (string)$stateId;
  }

  /**
   * Convenience getter for state array.
   *
   * @return array<string, mixed>
   */
  public function getState(): array {
    return $this->getAttribute('state') ?? [];
  }

  /**
   * Convenience getter for next position.
   */
  public function getNextPosition(): int {
    return (int)$this->getAttribute('next_position');
  }

  /**
   * Convenience getter for lock version.
   */
  public function getLockVersion(): int {
    return (int)$this->getAttribute('lock_version');
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
      ->where('state_id', $this->getAttribute('state_id'));
  }
}
