<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\Concerns\QuerySharding;
use SlimeSystems\Eloquent\Exceptions\InvalidShard;
use SlimeSystems\Eloquent\Exceptions\InvalidShardException;
use SlimeSystems\Eloquent\QuerySharding as QueryShardingHelper;

class ShardedTask extends Model
{
    use QuerySharding;

    protected $table = 'sharded_tasks';
    protected $guarded = [];
    public $timestamps = false;
}

class CustomColumnShardedTask extends Model
{
    use QuerySharding;

    protected $table = 'custom_sharded_tasks';
    protected $guarded = [];
    public $timestamps = false;
    protected $queryShardKeyName = '_qsk';
}

describe('QuerySharding Eloquent Concern & Utility', function () {
    beforeEach(function () {
        Schema::dropIfExists('sharded_tasks');
        Schema::dropIfExists('custom_sharded_tasks');

        Schema::create('sharded_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            QueryShardingHelper::createBlueprint($table);
        });

        Schema::create('custom_sharded_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            QueryShardingHelper::createBlueprint($table, '_qsk');
        });
    });

    test('generates random 31-bit integer shard key on model instantiation', function () {
        $task = new ShardedTask(['name' => 'Process Invoice']);

        expect($task->query_shard_key)->toBeInt()
            ->and($task->getQueryShardKey())->toBeInt()
            ->and($task->query_shard_key)->toBeGreaterThanOrEqual(0)
            ->and($task->query_shard_key)->toBeLessThanOrEqual(0x7fffffff);
    });

    test('populates shard key on model creation if not set', function () {
        $task = new ShardedTask();
        $task->name = 'Async Job';
        $task->query_shard_key = null;
        $task->save();

        $fresh = $task->fresh();
        expect($fresh->query_shard_key)->toBeInt()
            ->and($fresh->query_shard_key)->toBeGreaterThanOrEqual(0);
    });

    test('preserves explicitly assigned shard key', function () {
        $task = ShardedTask::create([
            'name' => 'Specific Key Task',
            'query_shard_key' => 12345,
        ]);

        $fresh = $task->fresh();
        expect($fresh->query_shard_key)->toBe(12345);
    });

    test('supports custom shard key column name', function () {
        $task = new CustomColumnShardedTask(['name' => 'Custom Task']);

        expect($task->getQueryShardKeyName())->toBe('_qsk')
            ->and($task->_qsk)->toBeInt()
            ->and($task->getQueryShardKey())->toBe($task->_qsk);

        $task->save();
        $fresh = $task->fresh();
        expect($fresh->_qsk)->toBe($task->_qsk);
    });

    test('partitions records across shards with complete coverage and zero overlap', function () {
        $totalRecords = 100;
        $shardCount = 4;

        for ($i = 0; $i < $totalRecords; $i++) {
            ShardedTask::create([
                'name' => "Task #{$i}",
                'query_shard_key' => $i * 17, // deterministic keys
            ]);
        }

        $allFoundIds = [];
        for ($shardNumber = 0; $shardNumber < $shardCount; $shardNumber++) {
            $shardTasks = ShardedTask::queryShard($shardCount, $shardNumber)->get();

            foreach ($shardTasks as $task) {
                // Verify modulo math
                expect($task->query_shard_key % $shardCount)->toBe($shardNumber);
                $allFoundIds[] = $task->id;
            }
        }

        // Must partition exactly: all 100 records present, no duplicates
        expect(count($allFoundIds))->toBe($totalRecords)
            ->and(array_unique($allFoundIds))->toHaveCount($totalRecords);
    });

    test('validates boundary shard parameters', function () {
        ShardedTask::create(['name' => 'Task', 'query_shard_key' => 10]);

        // Single shard worker covers all records
        $all = ShardedTask::queryShard(1, 0)->get();
        expect($all)->toHaveCount(1);

        // Max shard count 1024
        $result = ShardedTask::queryShard(1024, 10)->get();
        expect($result)->toHaveCount(1); // 10 % 1024 = 10
    });

    test('throws InvalidShardException on non-integer parameters', function () {
        expect(fn () => ShardedTask::queryShard('invalid', 0)->get())
            ->toThrow(InvalidShardException::class, 'Shard count and shard number must be integers.');

        expect(fn () => ShardedTask::queryShard(4, 'invalid')->get())
            ->toThrow(InvalidShardException::class, 'Shard count and shard number must be integers.');

        expect(fn () => ShardedTask::queryShard(4.5, 0)->get())
            ->toThrow(InvalidShardException::class, 'Shard count and shard number must be integers.');
    });

    test('throws InvalidShardException on invalid shard count', function () {
        expect(fn () => ShardedTask::queryShard(0, 0)->get())
            ->toThrow(InvalidShardException::class, 'Shard count must be between 1 and 1024');

        expect(fn () => ShardedTask::queryShard(-1, 0)->get())
            ->toThrow(InvalidShardException::class, 'Shard count must be between 1 and 1024');

        expect(fn () => ShardedTask::queryShard(1025, 0)->get())
            ->toThrow(InvalidShardException::class, 'Shard count must be between 1 and 1024');
    });

    test('throws InvalidShardException on invalid shard number', function () {
        expect(fn () => ShardedTask::queryShard(4, -1)->get())
            ->toThrow(InvalidShardException::class, 'Shard number must be between 0 and 3');

        expect(fn () => ShardedTask::queryShard(4, 4)->get())
            ->toThrow(InvalidShardException::class, 'Shard number must be between 0 and 3');

        expect(fn () => ShardedTask::queryShard(4, 5)->get())
            ->toThrow(InvalidShardException::class, 'Shard number must be between 0 and 3');
    });

    test('InvalidShard exception alias catches InvalidShardException for Ruby parity', function () {
        try {
            ShardedTask::queryShard(0, 0)->get();
            $this->fail('Expected InvalidShard to be thrown');
        } catch (InvalidShard $e) {
            expect($e)->toBeInstanceOf(InvalidShardException::class);
        }
    });

    test('QuerySharding::calculateShard computes correct shard modulo', function () {
        expect(QueryShardingHelper::calculateShard(10, 4))->toBe(2)
            ->and(QueryShardingHelper::calculateShard(12, 4))->toBe(0)
            ->and(QueryShardingHelper::calculateShard(7, 3))->toBe(1);

        expect(fn () => QueryShardingHelper::calculateShard(10, 0))
            ->toThrow(InvalidShardException::class);
    });
});
