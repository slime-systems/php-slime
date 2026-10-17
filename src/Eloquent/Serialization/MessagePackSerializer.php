<?php

namespace SlimeSystems\Eloquent\Serialization;

use MessagePack\BufferUnpacker;
use MessagePack\Packer;

class MessagePackSerializer {
  private static ?Packer $packer = null;
  private static ?BufferUnpacker $unpacker = null;

  private static function getPacker(): Packer {
    if (self::$packer === null) {
      self::$packer = new Packer(transformers: [
        new DateTimeExtension(),
        new ObjectIdExtension(),
      ]);
    }
    return self::$packer;
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
   * Pack any PHP data structure (including DateTimeInterface/Carbon) into MessagePack binary.
   */
  public static function pack(mixed $value): string {
    return self::getPacker()->pack($value);
  }

  /**
   * Unpack MessagePack binary into PHP data structures.
   */
  public static function unpack(string $bytes): mixed {
    $unpacker = self::getUnpacker();
    $unpacker->reset($bytes);
    return $unpacker->unpack();
  }
}
