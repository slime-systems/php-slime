<?php

namespace SlimeSystems\Hatsume;

use Throwable;

class UnknownCommand extends NotImplemented {
  public readonly ?string $command;

  public function __construct(
    string|object|null $command = null,
    int                $code = 0,
    ?Throwable         $previous = null
  ) {
    $this->command = is_object($command) ? get_class($command) : $command;
    $message = $this->command !== null
      ? sprintf('Unknown command: "%s"', $this->command)
      : 'Unknown command';

    parent::__construct(
      $message,
      $code,
      $previous
    );
  }
}
