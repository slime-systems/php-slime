<?php

declare(strict_types = 1);

namespace SlimeSystems\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use SlimeSystems\Support\BinaryHex;

class BinaryHexCast implements CastsAttributes {
  /**
   * Transform binary value from database to a BinaryHex instance.
   *
   * @param Model $model
   * @param string $key
   * @param mixed $value
   * @param array<string, mixed> $attributes
   */
  public function get(Model $model, string $key, mixed $value, array $attributes): ?BinaryHex {
    if ($value === null) {
      return null;
    }

    if (is_resource($value)) {
      $value = stream_get_contents($value);
    }

    if ($value instanceof BinaryHex) {
      return $value;
    }

    return BinaryHex::fromBinary((string)$value);
  }

  /**
   * Transform incoming value (BinaryHex, hex string, or raw binary) to raw binary for storage.
   *
   * @param Model $model
   * @param string $key
   * @param mixed $value
   * @param array<string, mixed> $attributes
   */
  public function set(Model $model, string $key, mixed $value, array $attributes): ?string {
    return BinaryHex::val($value);
  }

  /**
   * Transform attribute to its serialized (array/JSON) hex form.
   *
   * @param Model $model
   * @param string $key
   * @param mixed $value
   * @param array<string, mixed> $attributes
   */
  public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string {
    if ($value === null) {
      return null;
    }

    if ($value instanceof BinaryHex) {
      return $value->toHex();
    }

    if (is_resource($value)) {
      $value = stream_get_contents($value);
    }

    if (is_string($value) && ctype_xdigit($value) && (strlen($value) % 2 === 0)) {
      return $value;
    }

    return bin2hex((string)$value);
  }
}

