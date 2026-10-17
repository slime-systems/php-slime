<?php

namespace SlimeSystems\Eloquent\Serialization;

use MessagePack\BufferUnpacker;
use MessagePack\Extension;
use MessagePack\Packer;
use SlimeSystems\ObjectId;

class ObjectIdExtension implements Extension {
  private const TYPE = 0x07;

  public function getType(): int {
    return self::TYPE;
  }

  /**
   * Packs SlimeSystems\ObjectId into MessagePack BSON type 0x07 extension format.
   *
   * @param Packer $packer
   * @param mixed $value
   * @return string|null
   */
  public function pack(Packer $packer, mixed $value): ?string {
    if (!$value instanceof ObjectId) {
      return null;
    }

    return $packer->packExt(self::TYPE, $value->toBinary());
  }

  /**
   * Unpacks MessagePack BSON type 0x07 extension into a SlimeSystems\ObjectId instance.
   *
   * @param BufferUnpacker $unpacker
   * @param int $extLength
   * @return ObjectId
   */
  public function unpackExt(BufferUnpacker $unpacker, int $extLength): ObjectId {
    $bytes = $unpacker->read($extLength);
    return ObjectId::fromBinary($bytes);
  }
}
