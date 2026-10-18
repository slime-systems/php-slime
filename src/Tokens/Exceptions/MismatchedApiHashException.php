<?php

declare(strict_types = 1);

namespace SlimeSystems\Tokens\Exceptions;

use Throwable;

class MismatchedApiHashException extends TokenException {
  public function __construct(
    public readonly ?string $expectedHash = null,
    public readonly ?string $actualHash = null,
    string                  $message = 'Mismatched API hash',
    int                     $code = 0,
    ?Throwable              $previous = null
  ) {
    parent::__construct($message, $code, $previous);
  }
}
