<?php

namespace SlimeSystems\Eloquent\Serialization;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use MessagePack\BufferUnpacker;
use MessagePack\Extension;
use MessagePack\Packer;

class DateTimeExtension implements Extension {
  private const TYPE = -1;

  public function getType(): int {
    return self::TYPE;
  }

  /**
   * Packs DateTimeInterface values into MessagePack timestamp extension format.
   *
   * @param Packer $packer
   * @param mixed $value
   * @return string|null
   */
  public function pack(Packer $packer, mixed $value): ?string {
    if (!$value instanceof DateTimeInterface) {
      return null;
    }

    $sec = $value->getTimestamp();
    $usec = (int) $value->format('u');
    $nsec = $usec * 1000;

    // 96-bit format: 32-bit nsec + 64-bit signed sec (before 1970 or past 2106)
    if ($sec < 0 || ($sec >> 34) > 0) {
      return $packer->packExt(self::TYPE, pack('Nq', $nsec, $sec));
    }

    // 32-bit format: 32-bit unsigned sec (no nanoseconds, 1970-2106)
    if ($nsec === 0 && $sec <= 0xffffffff) {
      return $packer->packExt(self::TYPE, pack('N', $sec));
    }

    // 64-bit format: 30-bit nsec + 34-bit sec
    return $packer->packExt(self::TYPE, pack('J', ($nsec << 34) | $sec));
  }

  /**
   * Unpacks MessagePack timestamp extension into an Illuminate\Support\Carbon instance.
   *
   * @param BufferUnpacker $unpacker
   * @param int $extLength
   * @return Carbon
   */
  public function unpackExt(BufferUnpacker $unpacker, int $extLength): Carbon {
    if ($extLength === 4) {
      $data = $unpacker->read(4);
      $sec = unpack('N', $data)[1];
      return Carbon::createFromTimestampUTC($sec);
    }

    if ($extLength === 8) {
      $data = $unpacker->read(8);
      $num = unpack('J', $data)[1];
      $nsec = $num >> 34;
      if ($nsec < 0) {
        $nsec += 0x40000000;
      }
      $sec = $num & 0x3ffffffff;
      return Carbon::createFromTimestampUTC($sec)->addMicroseconds((int) ($nsec / 1000));
    }

    $data = $unpacker->read(12);
    $nsec = unpack('N', $data)[1];
    $sec = unpack('q', substr($data, 4))[1];
    return Carbon::createFromTimestampUTC($sec)->addMicroseconds((int) ($nsec / 1000));
  }
}
