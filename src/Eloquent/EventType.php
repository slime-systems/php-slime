<?php

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Eloquent\Model;
use SlimeSystems\Eloquent\Concerns\HasLookupTypeCache;

/**
 * @property int $id
 * @property string $name
 */
class EventType extends Model {
  use HasLookupTypeCache;

  /**
   * The table associated with the model.
   *
   * @var string
   */
  protected $table = 'event_types';

  /**
   * Indicates if the model should be timestamped.
   *
   * @var bool
   */
  public $timestamps = false;

  /**
   * The attributes that aren't mass assignable.
   *
   * @var list<string>
   */
  protected $guarded = [];
}
