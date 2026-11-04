<?php

declare(strict_types = 1);

namespace SlimeSystems\Eloquent\Exceptions;

use SlimeSystems\Exceptions\RapidException;

class InvalidShardException extends RapidException {
  public const string INVALID = __CLASS__ . '::INVALID';

  public function __construct(string $message = 'Invalid shard configuration.', mixed ...$metadata) {
    $metadata['message'] = $message;
    parent::__construct(self::INVALID, ...$metadata);
  }
}
