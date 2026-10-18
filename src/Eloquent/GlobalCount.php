<?php

namespace SlimeSystems\Eloquent;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

/**
 * GlobalCount Model
 *
 * Distributed, atomic, thread-safe sequential ID generator.
 * Uses Compare-And-Swap (CAS) to safely increment sequence counters across
 * concurrent workers without race conditions or deadlocks.
 *
 * @property string $key
 * @property int $next_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GlobalCount extends Model {
  public const DEFAULT_KEY = 'global-count';
  /**
   * Global default initial value resolver or scalar.
   *
   * @var int|Closure|null
   */
  protected static int|Closure|null $defaultInitialValue = null;
  /**
   * @var bool
   */
  public $incrementing = false;
  /**
   * @var string
   */
  protected $table = 'global_counts';
  /**
   * @var string
   */
  protected $primaryKey = 'key';
  /**
   * @var string
   */
  protected $keyType = 'string';
  /**
   * The attributes that aren't mass assignable.
   *
   * @var list<string>
   */
  protected $guarded = [];
  /**
   * @var array<string, string>
   */
  protected $casts = [
    'next_value' => 'integer',
  ];

  /**
   * Create the database table schema blueprint.
   */
  public static function createBlueprint(Blueprint $table): void {
    $table->string('key')->primary();
    $table->unsignedBigInteger('next_value')->default(1);
    $table->timestamps();
  }

  /**
   * Set a custom default initial value or factory closure.
   */
  public static function setDefaultInitialValue(int|Closure|null $value): void {
    static::$defaultInitialValue = $value;
  }

  /**
   * Generate a random positive initial offset matching RubySlime's formula.
   * `(1 + hex(3)) & 0x07ffff` => range [1, 524287].
   */
  public static function randomInitialValue(): int {
    return (1 + random_int(0, 0xffffff)) & 0x07ffff;
  }

  /**
   * Snake_case alias for nextValue() for direct RubySlime API compatibility.
   *
   * @param string $key
   * @param int|null $initialValue
   * @return int
   */
  public static function next_value(string $key = self::DEFAULT_KEY, ?int $initialValue = null): int {
    return static::nextValue($key, $initialValue);
  }

  /**
   * Atomically obtain the next sequence number and advance the counter.
   *
   * @param string $key Sequence identifier (defaults to 'global-count')
   * @param int|null $initialValue Initial starting value if sequence does not exist
   * @return int Allocated sequence number
   */
  public static function nextValue(string $key = self::DEFAULT_KEY, ?int $initialValue = null): int {
    $initial = $initialValue ?? static::resolveDefaultInitialValue();

    while (true) {
      // 1. Ensure the sequence record exists, handling race collisions on insert
      $record = static::where('key', $key)->first();
      if ($record === null) {
        try {
          $record = static::create([
            'key' => $key,
            'next_value' => $initial,
          ]);
        } catch (QueryException $e) {
          // Unique constraint collision if another worker inserted at the exact same moment
          $record = static::where('key', $key)->first();
          if ($record === null) {
            continue;
          }
        }
      }

      $currentValue = (int)$record->next_value;

      // 2. Atomic Compare-And-Swap (CAS) update
      $affected = static::where('key', $key)
        ->where('next_value', $currentValue)
        ->update([
          'next_value' => $currentValue + 1,
        ]);

      // 3. If update succeeded, this worker uniquely claimed $currentValue
      if ($affected > 0) {
        return $currentValue;
      }

      // If affected === 0, another worker incremented the counter. Retry loop.
    }
  }

  /**
   * Resolve the default starting value for new sequences.
   */
  public static function resolveDefaultInitialValue(): int {
    if (static::$defaultInitialValue instanceof Closure) {
      return (int)call_user_func(static::$defaultInitialValue);
    }

    if (is_int(static::$defaultInitialValue)) {
      return static::$defaultInitialValue;
    }

    return 1;
  }

  /**
   * Get the current next_value without incrementing, or null if sequence does not exist.
   *
   * @param string $key
   * @return int|null
   */
  public static function currentValue(string $key = self::DEFAULT_KEY): ?int {
    return static::peek($key)?->next_value;
  }

  /**
   * Peek at the current sequence record without incrementing.
   *
   * @param string $key
   * @return static|null
   */
  public static function peek(string $key = self::DEFAULT_KEY): ?static {
    return static::where('key', $key)->first();
  }

  /**
   * Reset or initialize a sequence to a specific value.
   *
   * @param string $key
   * @param int $value
   * @return static
   */
  public static function reset(string $key = self::DEFAULT_KEY, int $value = 1): static {
    return static::updateOrCreate(
      ['key' => $key],
      ['next_value' => $value]
    );
  }
}
