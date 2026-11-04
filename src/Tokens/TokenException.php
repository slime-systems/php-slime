<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens;

use SlimeSystems\Exceptions\RapidException;

class TokenException extends RapidException {
  public const string INVALID = __CLASS__ . '::INVALID';
  public const string EXPIRED = __CLASS__ . '::EXPIRED';
  public const string CLOCK_SKEW = __CLASS__ . '::CLOCK_SKEW';
  public const string KEY_NOT_FOUND = __CLASS__ . '::KEY_NOT_FOUND';
  public const string MISMATCHED_HASH = __CLASS__ . '::MISMATCHED_HASH';
  public const string SCHEMA_VIOLATION = __CLASS__ . '::SCHEMA_VIOLATION';

  public function __construct(string $identifier = self::INVALID, mixed ...$args) {
    if (str_contains($identifier, ' ') && !str_contains($identifier, '::')) {
      $args['message'] = $identifier;
      parent::__construct(self::INVALID, ...$args);
      return;
    }

    parent::__construct($identifier, ...$args);
  }
}
