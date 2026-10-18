<?php

namespace SlimeSystems\Eloquent\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use SlimeSystems\Eloquent\Exceptions\InvalidShardException;

/**
 * Trait QuerySharding
 *
 * Implements deterministic partition-based query sharding for distributed background workers.
 * Each model instance is assigned a 31-bit integer shard key upon initialization/creation.
 */
trait QuerySharding {
  /**
   * Boot the query sharding trait for an Eloquent model.
   */
  public static function bootQuerySharding(): void {
    static::saving(function (Model $model) {
      /** @var Model&QuerySharding $model */
      $keyName = $model->getQueryShardKeyName();
      if ($model->getAttribute($keyName) === null) {
        $model->setAttribute($keyName, static::generateQueryShardKey());
      }
    });
  }

  /**
   * Get the database column name used for query sharding.
   */
  public function getQueryShardKeyName(): string {
    return $this->queryShardKey ?? $this->queryShardKeyName ?? 'query_shard_key';
  }

  /**
   * Generate a random 31-bit positive integer shard key [0, 2147483647].
   */
  public static function generateQueryShardKey(): int {
    return random_int(0, 0x7fffffff);
  }

  /**
   * Schema blueprint helper for query sharding column.
   */
  public static function createQueryShardingBlueprint(Blueprint $table, string $column = 'query_shard_key'): void {
    $table->unsignedInteger($column)->default(0)->index();
  }

  /**
   * Initialize the trait on a new model instance.
   */
  public function initializeQuerySharding(): void {
    $keyName = $this->getQueryShardKeyName();
    if (!array_key_exists($keyName, $this->attributes)) {
      $this->attributes[$keyName] = static::generateQueryShardKey();
    }
  }

  /**
   * Ensure the query shard key is populated before saving.
   */
  public function save(array $options = []) {
    $keyName = $this->getQueryShardKeyName();
    if ($this->getAttribute($keyName) === null) {
      $this->setAttribute($keyName, static::generateQueryShardKey());
    }

    return parent::save($options);
  }

  /**
   * Get the query shard key value.
   */
  public function getQueryShardKey(): ?int {
    $value = $this->getAttribute($this->getQueryShardKeyName());

    return $value !== null ? (int)$value : null;
  }

  /**
   * Set the query shard key value.
   */
  public function setQueryShardKey(int $value): static {
    $this->setAttribute($this->getQueryShardKeyName(), $value);

    return $this;
  }

  /**
   * Scope the query to records allocated to a specific shard slice.
   *
   * @param Builder $query
   * @param mixed $shardCount Total number of shards (1 to 1024)
   * @param mixed $shardNumber Shard index for this worker (0 to shardCount - 1)
   * @return Builder
   *
   * @throws InvalidShardException
   */
  public function scopeQueryShard(Builder $query, mixed $shardCount, mixed $shardNumber): Builder {
    if (filter_var($shardCount, FILTER_VALIDATE_INT) === false || filter_var($shardNumber, FILTER_VALIDATE_INT) === false) {
      throw new InvalidShardException('Shard count and shard number must be integers.');
    }

    $shardCount = (int)$shardCount;
    $shardNumber = (int)$shardNumber;

    if ($shardCount <= 0 || $shardCount > 1024) {
      throw new InvalidShardException("Shard count must be between 1 and 1024, given: {$shardCount}");
    }

    if ($shardNumber < 0 || $shardNumber >= $shardCount) {
      throw new InvalidShardException("Shard number must be between 0 and " . ($shardCount - 1) . ", given: {$shardNumber}");
    }

    $column = $this->qualifyColumn($this->getQueryShardKeyName());
    $grammar = $query->getQuery()->getGrammar();
    $wrappedColumn = $grammar !== null ? $grammar->wrap($column) : $column;

    return $query->whereRaw("{$wrappedColumn} % ? = ?", [$shardCount, $shardNumber]);
  }
}
