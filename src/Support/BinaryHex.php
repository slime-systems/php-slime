<?php

declare(strict_types = 1);

namespace SlimeSystems\Support;

use InvalidArgumentException;
use JsonSerializable;
use Stringable;

readonly class BinaryHex implements JsonSerializable, Stringable {
  public function __construct(
    private string $binary,
  ) {
  }

  public static function fromBinary(string $binary): self {
    return new self($binary);
  }

  public static function fromHex(string $hex): self {
    if (!ctype_xdigit($hex) || (strlen($hex) % 2 !== 0)) {
      throw new InvalidArgumentException("Invalid hex string: '{$hex}'");
    }

    $binary = hex2bin($hex);
    if ($binary === false) {
      throw new InvalidArgumentException("Failed to decode hex string: '{$hex}'");
    }

    return new self($binary);
  }

  public static function from(self|string $value): self {
    if ($value instanceof self) {
      return $value;
    }

    if (ctype_xdigit($value) && (strlen($value) % 2 === 0)) {
      return self::fromHex($value);
    }

    return self::fromBinary($value);
  }

  /**
   * Resolve the value to raw binary for PDO bindings and query criteria.
   * Supports BinaryHex instances, hex strings, raw binary strings, and streams.
   */
  public static function val(mixed $value): ?string {
    if ($value === null) {
      return null;
    }

    if (is_resource($value)) {
      $value = stream_get_contents($value);
    }

    if ($value instanceof self) {
      return $value->toBinary();
    }

    if (is_string($value) && ctype_xdigit($value) && (strlen($value) % 2 === 0)) {
      $bin = hex2bin($value);
      return $bin !== false ? $bin : $value;
    }

    return (string)$value;
  }

  public function toHex(): string {
    return bin2hex($this->binary);
  }

  public function toString(): string {
    return $this->toHex();
  }

  public function toBinary(): string {
    return $this->binary;
  }

  /**
   * Stringable representation returns raw binary for PDO bindings.
   */
  public function __toString(): string {
    return $this->binary;
  }

  /**
   * JsonSerializable representation converts to clean hex for JSON APIs.
   */
  public function jsonSerialize(): string {
    return $this->toHex();
  }

  public function equals(mixed $other): bool {
    if ($other instanceof self) {
      return $this->binary === $other->binary;
    }

    if (is_string($other)) {
      if (ctype_xdigit($other) && (strlen($other) === strlen($this->binary) * 2)) {
        return strcasecmp($this->toHex(), $other) === 0;
      }

      return $this->binary === $other;
    }

    return false;
  }
}

