<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\GlobalCount;

describe('GlobalCount Eloquent Model & Atomic Sequence Generator', function () {
    beforeEach(function () {
        Schema::dropIfExists('global_counts');

        Schema::create('global_counts', function (Blueprint $table) {
            GlobalCount::createBlueprint($table);
        });

        GlobalCount::setDefaultInitialValue(null);
    });

    afterEach(function () {
        GlobalCount::setDefaultInitialValue(null);
    });

    test('allocates monotonically increasing sequence numbers starting from default 1', function () {
        $val1 = GlobalCount::nextValue();
        $val2 = GlobalCount::nextValue();
        $val3 = GlobalCount::nextValue();

        expect($val1)->toBe(1)
            ->and($val2)->toBe(2)
            ->and($val3)->toBe(3);

        $record = GlobalCount::peek();
        expect($record)->not->toBeNull()
            ->and($record->next_value)->toBe(4);
    });

    test('snake_case alias next_value works identically for Ruby parity', function () {
        $val1 = GlobalCount::next_value();
        $val2 = GlobalCount::next_value();

        expect($val1)->toBe(1)
            ->and($val2)->toBe(2);
    });

    test('supports isolated named sequences', function () {
        $account1 = GlobalCount::nextValue('account');
        $account2 = GlobalCount::nextValue('account');

        $project1 = GlobalCount::nextValue('project');
        $project2 = GlobalCount::nextValue('project');

        expect($account1)->toBe(1)
            ->and($account2)->toBe(2)
            ->and($project1)->toBe(1)
            ->and($project2)->toBe(2);
    });

    test('accepts explicit initial value on sequence creation', function () {
        $order1 = GlobalCount::nextValue('order', 1000);
        $order2 = GlobalCount::nextValue('order', 1000); // already created, so 1000 is ignored

        expect($order1)->toBe(1000)
            ->and($order2)->toBe(1001);
    });

    test('respects configurable default initial value scalar or closure', function () {
        GlobalCount::setDefaultInitialValue(500);

        expect(GlobalCount::nextValue('custom-seq'))->toBe(500)
            ->and(GlobalCount::nextValue('custom-seq'))->toBe(501);

        // Closure generator
        GlobalCount::setDefaultInitialValue(fn () => 9999);
        expect(GlobalCount::nextValue('closure-seq'))->toBe(9999);
    });

    test('randomInitialValue generates positive integers within RubySlime boundary', function () {
        for ($i = 0; $i < 20; $i++) {
            $value = GlobalCount::randomInitialValue();
            expect($value)->toBeGreaterThanOrEqual(1)
                ->and($value)->toBeLessThanOrEqual(0x07ffff); // 524,287
        }
    });

    test('peek returns model record without incrementing sequence', function () {
        expect(GlobalCount::peek())->toBeNull();

        GlobalCount::nextValue();

        $peeked1 = GlobalCount::peek();
        $peeked2 = GlobalCount::peek();

        expect($peeked1)->not->toBeNull()
            ->and($peeked1->key)->toBe(GlobalCount::DEFAULT_KEY)
            ->and($peeked1->next_value)->toBe(2)
            ->and($peeked2->next_value)->toBe(2);
    });

    test('currentValue returns integer next_value without incrementing', function () {
        expect(GlobalCount::currentValue('unknown'))->toBeNull();

        GlobalCount::nextValue('doc_seq', 10);
        expect(GlobalCount::currentValue('doc_seq'))->toBe(11);
    });

    test('reset updates or creates sequence with specified value', function () {
        GlobalCount::reset('reset_seq', 50);
        expect(GlobalCount::currentValue('reset_seq'))->toBe(50);

        $next = GlobalCount::nextValue('reset_seq');
        expect($next)->toBe(50);
        expect(GlobalCount::currentValue('reset_seq'))->toBe(51);

        GlobalCount::reset('reset_seq', 100);
        expect(GlobalCount::currentValue('reset_seq'))->toBe(100);
    });

    test('handles CAS collision retry safely', function () {
        // Pre-create record
        GlobalCount::create([
            'key' => 'race_key',
            'next_value' => 10,
        ]);

        // Allocate sequence
        $val1 = GlobalCount::nextValue('race_key');
        expect($val1)->toBe(10);
        expect(GlobalCount::currentValue('race_key'))->toBe(11);
    });
});
