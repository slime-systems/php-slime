<?php

declare(strict_types = 1);

namespace SlimeSystems\Database\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

class SlimeBlueprint {
  /**
   * Define a 16-byte fixed binary column for UUIDs.
   */
  public static function binaryUuid(Blueprint $table, string $column = 'id'): ColumnDefinition {
    return $table->binary($column, 16, fixed: true);
  }

  /**
   * Define a fixed-length binary column.
   */
  public static function fixedBinary(Blueprint $table, string $column, int $length): ColumnDefinition {
    return $table->binary($column, $length, fixed: true);
  }

  /**
   * Fluent alias for fixedBinary: defines a column of N bytes.
   */
  public static function bytes(Blueprint $table, string $column, int $length): ColumnDefinition {
    return self::fixedBinary($table, $column, $length);
  }

  /**
   * Register schema macros on Laravel's Blueprint.
   */
  public static function registerMacros(): void {
    if (!Blueprint::hasMacro('binaryUuid')) {
      Blueprint::macro('binaryUuid', function (string $column = 'id') {
        /** @var Blueprint $this */
        return SlimeBlueprint::binaryUuid($this, $column);
      });
    }

    if (!Blueprint::hasMacro('fixedBinary')) {
      Blueprint::macro('fixedBinary', function (string $column, int $length) {
        /** @var Blueprint $this */
        return SlimeBlueprint::fixedBinary($this, $column, $length);
      });
    }

    if (!Blueprint::hasMacro('bytes')) {
      Blueprint::macro('bytes', function (string $column, int $length) {
        /** @var Blueprint $this */
        return SlimeBlueprint::bytes($this, $column, $length);
      });
    }
  }
}

