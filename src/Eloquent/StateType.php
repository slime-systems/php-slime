<?php

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;

/**
 * @property int $id
 * @property string $name
 */
class StateType extends Model {
  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 'state_types';

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
    'id',
    'name',
  ];

  /**
   * In-memory cache for string-to-id mapping.
   *
   * @var array<string, int>
   */
  private static array $nameToIdCache = [];

  /**
   * In-memory cache for id-to-string mapping.
   *
   * @var array<int, string>
   */
  private static array $idToNameCache = [];

  /**
   * Defines default schema blueprint for state_types table.
   */
  public static function createBlueprint(Blueprint $table): void {
    $table->increments('id');
    $table->string('name')->unique();
  }

  /**
   * Resolve an integer type_id for a given string name, creating the record if needed.
   */
  public static function idFor(string $name): int {
    if (isset(self::$nameToIdCache[$name])) {
      return self::$nameToIdCache[$name];
    }

    /** @var static $record */
    $record = static::firstOrCreate(['name' => $name]);
    $id = (int) $record->id;

    self::$nameToIdCache[$name] = $id;
    self::$idToNameCache[$id] = $name;

    return $id;
  }

  /**
   * Resolve string type name for a given integer type_id.
   */
  public static function nameFor(int $id): ?string {
    if (isset(self::$idToNameCache[$id])) {
      return self::$idToNameCache[$id];
    }

    /** @var static|null $record */
    $record = static::find($id);
    if ($record === null) {
      return null;
    }

    $name = (string) $record->name;
    self::$idToNameCache[$id] = $name;
    self::$nameToIdCache[$name] = $id;

    return $name;
  }

  /**
   * Check if a type name has been registered in the database or cache.
   */
  public static function hasType(string $name): bool {
    if (isset(self::$nameToIdCache[$name])) {
      return true;
    }

    return static::where('name', $name)->exists();
  }

  /**
   * Clear in-memory lookup caches.
   */
  public static function clearCache(): void {
    self::$nameToIdCache = [];
    self::$idToNameCache = [];
  }

  /**
   * Relationship to associated State models.
   *
   * @return HasMany<State, $this>
   */
  public function states(): HasMany {
    return $this->hasMany(State::class, 'type_id');
  }
}
