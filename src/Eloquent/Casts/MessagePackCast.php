<?php

namespace SlimeSystems\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use SlimeSystems\Eloquent\Serialization\MessagePackSerializer;

class MessagePackCast implements CastsAttributes {
  /**
   * Cast the given binary value from database to PHP array.
   *
   * @param Model $model
   * @param string $key
   * @param mixed $value
   * @param array<string, mixed> $attributes
   * @return array<string, mixed>
   */
  public function get(Model $model, string $key, mixed $value, array $attributes): array {
    if (is_resource($value)) {
      $value = stream_get_contents($value);
    }

    if ($value === null || $value === '') {
      return [];
    }

    $unpacked = MessagePackSerializer::unpack($value);
    return is_array($unpacked) ? $unpacked : (array)$unpacked;
  }

  /**
   * Prepare the given value for binary storage.
   *
   * @param Model $model
   * @param string $key
   * @param mixed $value
   * @param array<string, mixed> $attributes
   * @return string|null
   */
  public function set(Model $model, string $key, mixed $value, array $attributes): ?string {
    if ($value === null) {
      return null;
    }

    return MessagePackSerializer::pack($value);
  }
}
