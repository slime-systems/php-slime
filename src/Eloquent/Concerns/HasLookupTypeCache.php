<?php

namespace SlimeSystems\Eloquent\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Throwable;

trait HasLookupTypeCache {
  /**
   * In-memory cache for string-to-id mapping per class.
   *
   * @var array<class-string, array<string, int>>
   */
  private static array $nameToIdCache = [];

  /**
   * In-memory cache for id-to-string mapping per class.
   *
   * @var array<class-string, array<int, string>>
   */
  private static array $idToNameCache = [];

  /**
   * Indicates if the cache has been bulk-primed from database per class.
   *
   * @var array<class-string, bool>
   */
  private static array $isPrimed = [];

  /**
   * Indicates if the underlying table has been verified available per class.
   *
   * @var array<class-string, bool>
   */
  private static array $isAvailable = [];

  /**
   * Check if the underlying table exists and is available for operations.
   */
  public static function isAvailable(): bool {
    $class = static::class;
    if (isset(self::$isAvailable[$class])) {
      return self::$isAvailable[$class];
    }

    self::primeCache();
    return self::$isAvailable[$class] = !empty(self::$isPrimed[$class]);
  }

  /**
   * Prime the in-memory cache with all existing records from database in a single query.
   */
  public static function primeCache(): void {
    $class = static::class;
    if (!empty(self::$isPrimed[$class])) {
      return;
    }

    if (!isset(self::$nameToIdCache[$class])) {
      self::$nameToIdCache[$class] = [];
      self::$idToNameCache[$class] = [];
    }

    try {
      $records = static::query()->get(['id', 'name']);
      foreach ($records as $record) {
        $id = (int)$record->id;
        $name = (string)$record->name;
        self::$nameToIdCache[$class][$name] = $id;
        self::$idToNameCache[$class][$id] = $name;
      }
      self::$isPrimed[$class] = true;
    } catch (Throwable) {
      // Table may not exist yet during migration or early boot
    }
  }

  /**
   * Defines default schema blueprint for lookup types table.
   */
  public static function createBlueprint(Blueprint $table): void {
    $table->increments('id');
    $table->string('name')->unique();
  }

  /**
   * Resolve an integer id for a given string name, creating the record if needed.
   */
  public static function idFor(string $name): int {
    $class = static::class;
    self::primeCache();

    if (isset(self::$nameToIdCache[$class][$name])) {
      return self::$nameToIdCache[$class][$name];
    }

    /** @var static $record */
    $record = static::firstOrCreate(['name' => $name]);
    $id = (int)$record->id;

    self::$nameToIdCache[$class][$name] = $id;
    self::$idToNameCache[$class][$id] = $name;

    return $id;
  }

  /**
   * Resolve string name for a given integer id.
   */
  public static function nameFor(int $id): ?string {
    $class = static::class;
    self::primeCache();

    if (isset(self::$idToNameCache[$class][$id])) {
      return self::$idToNameCache[$class][$id];
    }

    /** @var static|null $record */
    $record = static::find($id);
    if ($record === null) {
      return null;
    }

    $name = (string)$record->name;
    self::$idToNameCache[$class][$id] = $name;
    self::$nameToIdCache[$class][$name] = $id;

    return $name;
  }

  /**
   * Check if a type name has been registered in the database or cache.
   */
  public static function hasType(string $name): bool {
    $class = static::class;
    self::primeCache();

    if (isset(self::$nameToIdCache[$class][$name])) {
      return true;
    }

    return static::where('name', $name)->exists();
  }

  /**
   * Clear in-memory lookup caches.
   */
  public static function clearCache(): void {
    $class = static::class;
    unset(self::$nameToIdCache[$class], self::$idToNameCache[$class], self::$isPrimed[$class], self::$isAvailable[$class]);
  }
}
