<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Exceptions;

use RuntimeException;
use Throwable;

class TokenException extends RuntimeException {
  public function __construct(string $message = 'Token verification failed', int $code = 0, ?Throwable $previous = null) {
    parent::__construct($message, $code, $previous);
  }
}
