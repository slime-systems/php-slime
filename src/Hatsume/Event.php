<?php

namespace SlimeSystems\Hatsume;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

trait Event {
  public readonly array $data;
  public readonly mixed $metadata;
  public readonly Carbon $occurredAt;

  public function __construct(
    array $data = [],
    mixed $metadata = null,
    ?DateTimeInterface $occurredAt = null,
  ) {
    $this->data = $data;
    $this->metadata = $metadata;
    $this->occurredAt = $occurredAt ? Carbon::instance($occurredAt) : Carbon::now();
  }

  /**
   * Factory constructor that enforces schema validation.
   *
   * @throws ValidationException
   */
  public static function strict(
    array $data = [],
    mixed $metadata = null,
    ?DateTimeInterface $occurredAt = null,
  ): static {
    $rules = static::rules();

    if (!empty($rules)) {
      $validator = Validator::make($data, $rules);
      if ($validator->fails()) throw new ValidationException($validator);
      $data = $validator->validated();
    }
    return new static($data, $metadata, $occurredAt);
  }

  /**
   * Define validation rules for the event payload.
   * Override this method in event classes that require validation.
   */
  protected static function rules(): array {
    return [];
  }
}
