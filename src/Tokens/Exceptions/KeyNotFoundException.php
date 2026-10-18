<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Exceptions;

use Throwable;

class KeyNotFoundException extends TokenException {
  public function __construct(string $message = 'JWT key not found', int $code = 0, ?Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
  }
}
