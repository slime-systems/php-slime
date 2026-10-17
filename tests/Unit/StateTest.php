<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use SlimeSystems\Eloquent\State;
use SlimeSystems\Eloquent\StateType;

describe('State Eloquent Model', function () {
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

    test('initializes with default attribute values and composite key', function () {
        $state = new State();

        expect($state->getTable())->toBe('process_states')
            ->and($state->getKeyName())->toBe(['type_id', 'state_id'])
            ->and($state->getIncrementing())->toBeFalse()
            ->and($state->state)->toBe([])
            ->and($state->getState())->toBe([])
            ->and($state->next_position)->toBe(1)
            ->and($state->getNextPosition())->toBe(1)
            ->and($state->lock_version)->toBe(0)
            ->and($state->getLockVersion())->toBe(0);
    });

    test('createBlueprint helper creates table with composite primary key schema', function () {
        expect(Schema::hasTable('process_states'))->toBeTrue()
            ->and(Schema::hasColumn('process_states', 'type_id'))->toBeTrue()
            ->and(Schema::hasColumn('process_states', 'state_id'))->toBeTrue()
            ->and(Schema::hasColumn('process_states', 'state'))->toBeTrue()
            ->and(Schema::hasColumn('process_states', 'next_position'))->toBeTrue()
            ->and(Schema::hasColumn('process_states', 'lock_version'))->toBeTrue();
    });

    test('persists and retrieves record with string state_id (e.g. UUID)', function () {
        $typeId = StateType::idFor('account');

        $state = new State([
            'type_id' => $typeId,
            'state_id' => 'usr_98a76b5c-4321',
            'state' => ['balance' => 500, 'currency' => 'THB'],
            'next_position' => 5,
            'lock_version' => 2,
        ]);
        $state->save();

        $loaded = State::findByTypeAndId('account', 'usr_98a76b5c-4321');
        expect($loaded)->not->toBeNull()
            ->and($loaded->getTypeId())->toBe($typeId)
            ->and($loaded->getTypeName())->toBe('account')
            ->and($loaded->getStateId())->toBe('usr_98a76b5c-4321')
            ->and($loaded->state)->toBe(['balance' => 500, 'currency' => 'THB'])
            ->and($loaded->next_position)->toBe(5)
            ->and($loaded->lock_version)->toBe(2);
    });

    test('persists and retrieves record with integer/bigint state_id', function () {
        $typeId = StateType::idFor('simple-transaction');

        $bigintId = 9007199254740991;

        $state = new State([
            'type_id' => $typeId,
            'state_id' => $bigintId,
            'state' => ['total' => 12500],
        ]);
        $state->save();

        // Query with integer
        $loadedInt = State::findByTypeAndId('simple-transaction', $bigintId);
        expect($loadedInt)->not->toBeNull()
            ->and($loadedInt->getStateId())->toBe($bigintId)
            ->and($loadedInt->state)->toBe(['total' => 12500]);

        // Query with stringified int
        $loadedStr = State::findByTypeAndId('simple-transaction', (string) $bigintId);
        expect($loadedStr)->not->toBeNull()
            ->and($loadedStr->getStateId())->toBe($bigintId);
    });

    test('supports native save() updates via setKeysForSaveQuery', function () {
        $typeId = StateType::idFor('order');

        $state = new State([
            'type_id' => $typeId,
            'state_id' => 101,
            'state' => ['status' => 'pending'],
        ]);
        $state->save();

        // Update via save()
        $found = State::findByTypeAndId($typeId, 101);
        $found->state = ['status' => 'paid'];
        $found->save();

        $reloaded = State::findByTypeAndId($typeId, 101);
        expect($reloaded->state['status'])->toBe('paid');
    });

    test('belongsTo type relationship resolves StateType model', function () {
        $typeId = StateType::idFor('subscription');

        $state = new State([
            'type_id' => $typeId,
            'state_id' => 'sub_123',
            'state' => [],
        ]);
        $state->save();

        expect($state->type)->toBeInstanceOf(StateType::class)
            ->and($state->type->name)->toBe('subscription')
            ->and($state->getTypeName())->toBe('subscription');
    });
});
