<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;

describe('StateType Eloquent Model', function () {
    beforeEach(function () {
        StateType::clearCache();
        Schema::dropIfExists('process_states');
        Schema::dropIfExists('state_types');

        Schema::create('state_types', function (Blueprint $table) {
            StateType::createBlueprint($table);
        });
        Schema::create('process_states', function (Blueprint $table) {
            State::createBlueprint($table);
        });
    });

    afterEach(function () {
        StateType::clearCache();
    });

    test('creates schema with correct columns', function () {
        expect(Schema::hasTable('state_types'))->toBeTrue()
            ->and(Schema::hasColumn('state_types', 'id'))->toBeTrue()
            ->and(Schema::hasColumn('state_types', 'name'))->toBeTrue();
    });

    test('resolves and caches type_id for string name via idFor', function () {
        $id1 = StateType::idFor('account');
        $id2 = StateType::idFor('simple-transaction');

        expect($id1)->toBeInt()->toBe(1)
            ->and($id2)->toBeInt()->toBe(2);

        // Subsequent call returns cached id
        $id1Cached = StateType::idFor('account');
        expect($id1Cached)->toBe($id1);

        // Only two records exist in database
        expect(StateType::count())->toBe(2);
    });

    test('resolves string name for type_id via nameFor', function () {
        $id = StateType::idFor('order');

        expect(StateType::nameFor($id))->toBe('order')
            ->and(StateType::nameFor(999))->toBeNull();
    });

    test('checks existence with hasType', function () {
        expect(StateType::hasType('payment'))->toBeFalse();

        StateType::idFor('payment');

        expect(StateType::hasType('payment'))->toBeTrue();
    });

    test('clears in-memory caches via clearCache', function () {
        $id = StateType::idFor('invoice');
        StateType::clearCache();

        // Still resolves from DB and re-caches
        expect(StateType::idFor('invoice'))->toBe($id);
    });

    test('provides states hasMany relationship', function () {
        $typeId = StateType::idFor('wallet');

        $state = new State([
            'type_id' => $typeId,
            'state_id' => 'wallet-1',
            'state' => ['balance' => 100],
        ]);
        $state->save();

        $type = StateType::find($typeId);
        expect($type->states)->toHaveCount(1)
            ->and($type->states->first()->state_id)->toBe('wallet-1');
    });
});
