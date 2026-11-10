<?php

declare(strict_types=1);

namespace SlimeSystems\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Schema\Blueprint;
use SlimeSystems\Eloquent\StateType;

trait HasStateKey {
  /**
   * Resolve composite key attributes from a structured array or scalar.
   *
   * @param array<string, mixed> $key
   * @return array{type_id: int, state_id: string}
   */
  public static function resolveStateKey(array $key): array {
    $type = $key['type_id'] ?? $key['type'];
    $id = $key['state_id'] ?? $key['id'] ?? null;

    return [
      'type_id' => is_int($type) ? $type : StateType::idFor((string)$type),
      'state_id' => is_array($id) ? json_encode($id) : (string)$id,
    ];
  }

  /**
   * Alias for resolveStateKey.
   *
   * @param array<string, mixed> $key
   * @return array{type_id: int, state_id: string}
   */
  public static function resolveKey(array $key): array {
    return static::resolveStateKey($key);
  }

  /**
   * Scope query to matching composite state key.
   *
   * @param Builder<static> $query
   * @param mixed $key
   * @return Builder<static>
   */
  public function scopeWhereStateKey(Builder $query, mixed $key): Builder {
    $resolved = static::resolveStateKey(is_array($key) ? $key : ['id' => $key]);
    return $query->where('type_id', $resolved['type_id'])->where('state_id', $resolved['state_id']);
  }

  /**
   * Accessor for string type name resolved from StateType cache.
   */
  protected function typeName(): Attribute {
    return Attribute::get(fn() => $this->type_id !== null ? StateType::nameFor((int)$this->type_id) : null);
  }

  /**
   * Convenience getter for string type name resolved from StateType cache.
   */
  public function getTypeName(): ?string {
    return $this->type_name;
  }

  /**
   * Add composite state key columns to schema blueprint.
   */
  public static function createStateKeyBlueprint(Blueprint $table): void {
    $table->unsignedInteger('type_id');
    $table->string('state_id');
  }
}

