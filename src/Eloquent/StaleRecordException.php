<?php

declare(strict_types = 1);

namespace SlimeSystems\Eloquent;

use SlimeSystems\Exceptions\RapidException;
use SlimeSystems\Hatsume\ConcurrentWriteDetected;

class StaleRecordException extends RapidException implements ConcurrentWriteDetected {
  public const string CONCURRENT_WRITE = __CLASS__ . '::CONCURRENT_WRITE';

  public function __construct(
    string $message = 'Concurrent write detected for state record.',
    mixed ...$metadata,
  ) {
    $metadata['message'] = $message;
    parent::__construct(self::CONCURRENT_WRITE, ...$metadata);
  }
}
