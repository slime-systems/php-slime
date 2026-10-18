<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Exceptions;

use Throwable;

class InvalidTokenException extends TokenException {
  public function __construct(string $message = 'Invalid token format or signature', int $code = 0, ?Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
  }
}
