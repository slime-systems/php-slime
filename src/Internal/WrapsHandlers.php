<?php

namespace SlimeSystems\Internal;

use Closure;
use ReflectionFunction;

/**
 * @internal
 */
trait WrapsHandlers {
  private function wrapHandler(callable $handler): Closure {
    $closure = $handler instanceof Closure ? $handler : $handler(...);
    $ref = new ReflectionFunction($closure);

    return match ($ref->getNumberOfParameters() > 1 || $ref->isVariadic()) {
      true => $closure,
      false => static fn(mixed $event, array $metadata = []) => $closure($event),
    };
  }
}
