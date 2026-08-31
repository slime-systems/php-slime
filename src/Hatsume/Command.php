<?php

namespace SlimeSystems\Hatsume;

use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\Model;

trait Command {
  protected ?array $validatedCache = null;

  abstract protected function rules(): array;

  public function validatedAttributes(): array {
    if ($this->validatedCache !== null) {
      return $this->validatedCache;
    }

    // Compact attributes (remove nulls)
    $normalized = array_filter($this->attributes(), fn($val) => $val !== null);

    $validator = Validator::make($normalized, $this->rules());

    if ($validator->fails()) {
      throw new ValidationException($validator);
    }

    return $this->validatedCache = $validator->validated();
  }

  public function attributes(): array {
    $vars = get_object_vars($this);
    unset($vars['validatedCache']);

    return $vars;
  }

  // --- ArrayAccess Methods ---

  public function offsetExists(mixed $offset): bool {
    return array_key_exists($offset, $this->validatedAttributes());
  }

  public function offsetGet(mixed $offset): mixed {
    return $this->validatedAttributes()[$offset] ?? null;
  }

  /** @noinspection PhpUnusedParameterInspection */
  public function offsetSet(mixed $_offset, mixed $_value): void {
    throw new NotImplemented('Validated attributes are immutable.');
  }

  /** @noinspection PhpUnusedParameterInspection */
  public function offsetUnset(mixed $_offset): void {
    throw new NotImplemented('Validated attributes are immutable.');
  }
}
