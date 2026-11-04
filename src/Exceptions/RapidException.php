<?php

declare(strict_types = 1);

namespace SlimeSystems\Exceptions;

use Exception;
use JsonSerializable;
use Throwable;

class RapidException extends Exception implements JsonSerializable {
  public readonly string $identifier;
  /**
   * @var array<string, mixed>
   */
  public readonly array $metadata;

  public function __construct(string $identifier, mixed ...$args) {
    $positional = array_values(array_filter($args, 'is_int', ARRAY_FILTER_USE_KEY));
    $named = array_filter($args, 'is_string', ARRAY_FILTER_USE_KEY);

    $message = (string)($named['message'] ?? ($positional[0] ?? ''));
    $code = (int)($named['code'] ?? ($positional[1] ?? 0));
    $previous = $named['previous'] ?? ($positional[2] ?? null);

    unset($named['message'], $named['code'], $named['previous']);

    parent::__construct($message, $code, $previous instanceof Throwable ? $previous : null);

    $this->identifier = $identifier;
    $this->metadata = $named;
  }

  public static function fromThrowable(Throwable $e, string $identifier, mixed ...$metadata): static {
    if ($e instanceof static && $e->identifier === $identifier) {
      return $e;
    }

    $metadata['message'] = $e->getMessage();
    $metadata['code'] = (int)$e->getCode();
    $metadata['previous'] = $e;

    return new static($identifier, ...$metadata);
  }

  public function is(string $identifier): bool {
    return $this->identifier === $identifier;
  }

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array {
    $data = [
      'code' => $this->identifier,
    ];

    $message = $this->getMessage();
    if ($message !== '') {
      $data['message'] = $message;
    }

    if (!empty($this->metadata)) {
      $data['metadata'] = $this->metadata;
    }

    return $data;
  }

  /**
   * @return array<string, mixed>
   */
  public function jsonSerialize(): array {
    return $this->toArray();
  }
}
