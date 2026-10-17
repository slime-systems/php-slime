<?php

namespace SlimeSystems\Eloquent;

use Illuminate\Database\Schema\Blueprint;
use SlimeSystems\Eloquent\Concerns\QuerySharding as QueryShardingTrait;
use SlimeSystems\Eloquent\Exceptions\InvalidShardException;

class QuerySharding
{
    public const MAX_SHARD_COUNT = 1024;
    public const DEFAULT_COLUMN = 'query_shard_key';

    /**
     * Generate a random 31-bit integer shard key [0, 2147483647].
     */
    public static function generateKey(): int
    {
        return QueryShardingTrait::generateQueryShardKey();
    }

    /**
     * Compute the shard index (modulo) for a given shard key.
     *
     * @param int $shardKey
     * @param int $shardCount
     * @return int
     *
     * @throws InvalidShardException
     */
    public static function calculateShard(int $shardKey, int $shardCount): int
    {
        if ($shardCount <= 0 || $shardCount > self::MAX_SHARD_COUNT) {
            throw new InvalidShardException("Shard count must be between 1 and " . self::MAX_SHARD_COUNT . ", given: {$shardCount}");
        }

        return $shardKey % $shardCount;
    }

    /**
     * Create database column and index blueprint.
     */
    public static function createBlueprint(Blueprint $table, string $column = self::DEFAULT_COLUMN): void
    {
        $table->unsignedInteger($column)->default(0)->index();
    }
}
