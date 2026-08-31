<?php

namespace SlimeSystems\Hatsume;

use Illuminate\Support\Facades\Validator;

trait Command {
  protected ?array $validatedCache = null;

  abstract protected function rules(): array;

  public function attributes(): array {
    $vars = get_object_vars($this);
    unset($vars['validatedCache']);
    return $vars;
  }

  public function validatedAttributes(): array {
    if ($this->validatedCache !== null) return $this->validatedCache;
    return $this->validatedCache = $this->validated();
  }

  /**
   * Validate data, cast values based on rules, and update command properties.
   */
  private function validated(): array {
    // Normalize empty strings to null for consistent nullable handling
    $compacted = array_map(
      fn($val) => ($val === '') ? null : $val,
      $this->attributes(),
    );

    $rules = $this->rules();
    $validator = Validator::make($compacted, $rules);

    if ($validator->fails()) {
      throw new ValidationException($validator);
    }

    $validated = $validator->validated();

    foreach ($validated as $key => $value) {
      $castValue = $this->castByRule($value, $rules[$key] ?? []);
      $validated[$key] = $castValue;
    }

    return $validated;
  }

  /**
   * Infer type cast from validation rules
   */
  protected function castByRule(mixed $value, array $rules): mixed {
    if ($value === null) {
      return null;
    }

    foreach ($rules as $rule) {
      if (!is_string($rule)) continue;

      [$ruleName] = explode(':', $rule, 2);
      $ruleName = strtolower($ruleName);

      $cast = match ($ruleName) {
        'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
        'integer', 'int', 'digits', 'digits_between' => (int)$value,
        'numeric', 'decimal' => is_numeric($value) ? $value + 0 : $value,
        'string' => (string)$value,
        default => null,
      };

      if ($cast !== null) return $cast;
    }

    return $value;
  }

  // --- ArrayAccess Implementation ---

  public function offsetExists(mixed $offset): bool {
    return array_key_exists($offset, $this->validated());
  }

  public function offsetGet(mixed $offset): mixed {
    return $this->validated()[$offset] ?? null;
  }

  public function offsetSet(mixed $_offset, mixed $_value): void {
    throw new NotImplemented('Validated attributes are immutable.');
  }

  public function offsetUnset(mixed $_offset): void {
    throw new NotImplemented('Validated attributes are immutable.');
  }
}
