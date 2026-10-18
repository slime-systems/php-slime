<?php

namespace SlimeSystems\Eloquent\Concerns;

use Illuminate\Database\QueryException;

/**
 * Trait ModelHelpers
 *
 * Provides high-concurrency write helpers modeled after RubySlime's ModelHelpers:
 * - indateRecord(): optimistic insert-first with fallback to update on unique key collision.
 * - upsertRecord(): optimistic update-first with fallback to insert and collision retry.
 */
trait ModelHelpers {
  /**
   * Insert the record first, or update if unique collision occurs (indate).
   *
   * @param array<string, mixed> $scope
   * @param array<string, mixed> $attributes
   * @return static
   */
  public static function indateRecord(array $scope, array $attributes = []): static {
    try {
      return static::create(array_merge($scope, $attributes));
    } catch (QueryException $e) {
      $record = static::where($scope)->first();
      if ($record !== null) {
        $record->update($attributes);
        return $record->fresh() ?? $record;
      }

      throw $e;
    }
  }

  /**
   * Update the record if it exists, or create it with collision retries (upsert).
   *
   * @param array<string, mixed> $scope
   * @param array<string, mixed> $attributes
   * @return static
   */
  public static function upsertRecord(array $scope, array $attributes = []): static {
    $record = static::where($scope)->first();
    if ($record !== null) {
      $record->update($attributes);
      return $record->fresh() ?? $record;
    }

    $lastException = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
      try {
        return static::create(array_merge($scope, $attributes));
      } catch (QueryException $e) {
        $lastException = $e;
        $record = static::where($scope)->first();
        if ($record !== null) {
          $record->update($attributes);
          return $record->fresh() ?? $record;
        }
      }
    }

    throw $lastException;
  }
}
