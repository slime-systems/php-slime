<?php

namespace SlimeSystems\Eloquent;

use RuntimeException;
use SlimeSystems\Hatsume\ConcurrentWriteDetected;
use Throwable;

class StaleRecordException extends RuntimeException implements ConcurrentWriteDetected {
  public function __construct(
    string $message = 'Concurrent write detected for state record.',
    int $code = 0,
    ?Throwable $previous = null,
  ) {
    parent::__construct($message, $code, $previous);
  }
}
