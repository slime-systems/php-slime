<?php

namespace SlimeSystems;

use ArrayAccess;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait Command {
  protected ?array $validatedCache = null;

  abstract protected function rules(): array;

  public function attributes(): array
  {
    return get_object_vars($this);
  }

  public function validatedAttributes(): array
  {
    if ($this->validatedCache !== null) {
      return $this->validatedCache;
    }

    // Compact attributes (remove nulls)
    $normalized = array_filter($this->attributes(), fn ($val) => $val !== null);

    $validator = Validator::make($normalized, $this->rules());

    if ($validator->fails()) {
      throw new ValidationException($validator);
    }

    return $this->validatedCache = $validator->validated();
  }

  // --- ArrayAccess Methods (Delegates [] to validatedAttributes) ---

  public function offsetExists(mixed $key): bool
  {
    return array_key_exists($key, $this->validatedAttributes());
  }

  public function offsetGet(mixed $key): mixed
  {
    return $this->validatedAttributes()[$key] ?? null;
  }

  public function offsetSet(mixed $offset, mixed $value): void
  {
    throw new \LogicException('Validated attributes are immutable.');
  }

  public function offsetUnset(mixed $offset): void
  {
    throw new \LogicException('Validated attributes are immutable.');
  }
}
