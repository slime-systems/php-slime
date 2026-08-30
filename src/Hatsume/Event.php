<?php

namespace SlimeSystems\Hatsume;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait Event {
  public readonly array $data;
  public readonly mixed $metadata;

  public function __construct(array $data = [], mixed $metadata = null) {
    $this->data = $data;
    $this->metadata = $metadata;
  }

  /**
   * Factory constructor that enforces schema validation.
   *
   * @throws ValidationException
   */
  public static function strict(array $data = [], mixed $metadata = null): static {
    $rules = static::rules();

    if (!empty($rules)) {
      // Validator::validate() throws ValidationException on failure
      $data = Validator::make($data, $rules)->validate();
    }

    return new static($data, $metadata);
  }

  /**
   * Define validation rules for the event payload.
   * Override this method in event classes that require validation.
   */
  protected static function rules(): array {
    return [];
  }
}
