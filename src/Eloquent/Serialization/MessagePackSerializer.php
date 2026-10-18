<?php

namespace SlimeSystems\Eloquent\Serialization;

use MessagePack\BufferUnpacker;
use MessagePack\Packer;
use SlimeSystems\Eloquent\FieldType;

class MessagePackSerializer {
  private static ?Packer $packer = null;
  private static ?BufferUnpacker $unpacker = null;
  private static bool $internKeys = true;

  /**
   * Configure whether dictionary key interning is enabled.
   */
  public static function setInternKeys(bool $enabled): void {
    self::$internKeys = $enabled;
  }

  /**
   * Pack any PHP data structure (including DateTimeInterface/Carbon) into MessagePack binary.
   */
  public static function pack(mixed $value, ?bool $internKeys = null): string {
    $shouldIntern = $internKeys ?? self::isInternKeysEnabled();
    if ($shouldIntern && is_array($value)) {
      $value = self::internKeys($value);
    }

    return self::getPacker()->pack($value);
  }

  /**
   * Determine if key interning is currently active.
   */
  public static function isInternKeysEnabled(): bool {
    return self::$internKeys && FieldType::isAvailable();
  }

  /**
   * Recursively replace string map keys with integer field IDs.
   */
  public static function internKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    $isList = array_is_list($value);
    $result = [];
    foreach ($value as $k => $v) {
      $newKey = (!$isList && is_string($k)) ? FieldType::idFor($k) : $k;
      $result[$newKey] = is_array($v) ? self::internKeys($v) : $v;
    }

    return $result;
  }

  private static function getPacker(): Packer {
    if (self::$packer === null) {
      self::$packer = new Packer(transformers: [
        new DateTimeExtension(),
        new ObjectIdExtension(),
      ]);
    }
    return self::$packer;
  }

  /**
   * Unpack MessagePack binary into PHP data structures.
   */
  public static function unpack(string $bytes, ?bool $restoreKeys = null): mixed {
    $unpacker = self::getUnpacker();
    $unpacker->reset($bytes);
    $value = $unpacker->unpack();

    $shouldRestore = $restoreKeys ?? FieldType::isAvailable();
    if ($shouldRestore && is_array($value)) {
      return self::restoreKeys($value);
    }

    return $value;
  }

  private static function getUnpacker(): BufferUnpacker {
    if (self::$unpacker === null) {
      self::$unpacker = new BufferUnpacker(extensions: [
        new DateTimeExtension(),
        new ObjectIdExtension(),
      ]);
    }
    return self::$unpacker;
  }

  /**
   * Recursively restore integer field IDs to original string keys.
   */
  public static function restoreKeys(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    $isList = array_is_list($value);
    $result = [];
    foreach ($value as $k => $v) {
      $newKey = (!$isList && is_int($k)) ? (FieldType::nameFor($k) ?? $k) : $k;
      $result[$newKey] = is_array($v) ? self::restoreKeys($v) : $v;
    }

    return $result;
  }
}
