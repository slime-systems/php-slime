<?php

namespace SlimeSystems\Hatsume;

use Throwable;

class UnknownCommand extends NotImplemented {
  public function __construct(
    public readonly string $command,
    int                    $code = 0,
    ?Throwable             $previous = null
  ) {
    parent::__construct(
      sprintf('Unknown command: "%s"', $command),
      $code,
      $previous
    );
  }
}
